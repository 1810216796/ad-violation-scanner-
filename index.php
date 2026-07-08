<?php
/**
 * 广告违禁词扫描监控面板 - 表格站点切换版（状态判断修正）
 */

$baseDir = __DIR__;
$logFile = $baseDir . '/scan.log';
$scannedFile = $baseDir . '/scanned_urls.txt';
$violationsFile = $baseDir . '/violations_results.txt';

// --- 工具函数 ---
function getLineCount($file) {
    if (!file_exists($file)) return 0;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return count($lines);
}

function getViolationCount($file) {
    if (!file_exists($file)) return 0;
    $content = file_get_contents($file);
    return substr_count($content, "\nURL: ");
}

function isProcessRunning($logFile) {
    if (!file_exists($logFile)) return false;
    $mtime = filemtime($logFile);
    return (time() - $mtime) < 60;
}

function getTailLog($file, $lines = 30) {
    if (!file_exists($file)) return [];
    $output = [];
    $fp = fopen($file, 'r');
    fseek($fp, -min(filesize($file), 1024 * 1024), SEEK_END);
    while (!feof($fp)) {
        $line = fgets($fp);
        if ($line !== false) $output[] = $line;
        if (count($output) > $lines * 2) array_shift($output);
    }
    fclose($fp);
    return array_slice($output, -$lines);
}

function getCurrentScanningUrl($logFile) {
    if (!file_exists($logFile)) return '暂无';
    $lines = file($logFile);
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = $lines[$i];
        if (preg_match('/https?:\/\/[^\s]+/', $line, $matches)) {
            return $matches[0];
        }
    }
    return '暂无';
}

/**
 * 解析违规文件，返回按违禁词聚合的数据（逐行解析，保证URL完整）
 */
function parseViolationsByWord($file, $siteFilter = null) {
    if (!file_exists($file)) return [];
    $content = file_get_contents($file);
    $blocks = preg_split('/\n-{76,}\n/', $content);
    $wordMap = [];

    foreach ($blocks as $block) {
        $block = trim($block);
        if (empty($block)) continue;
        $lines = explode("\n", $block);
        $site = '';
        $url = '';
        $currentWord = null;
        $currentType = null;
        $currentContext = null;

        foreach ($lines as $line) {
            $line = rtrim($line);
            if (strpos($line, '站点:') === 0) {
                $site = trim(substr($line, strlen('站点:')));
                continue;
            }
            if (strpos($line, 'URL:') === 0) {
                $url = trim(substr($line, strlen('URL:')));
                continue;
            }
            if (preg_match('/^\s+违禁词:\s*(.+?)\s*\(类型:\s*(.+?)\)$/', $line, $matches)) {
                if ($currentWord !== null && $currentContext !== null) {
                    if (!isset($wordMap[$currentWord])) {
                        $wordMap[$currentWord] = ['count' => 0, 'details' => []];
                    }
                    $wordMap[$currentWord]['count']++;
                    $wordMap[$currentWord]['details'][] = [
                        'site'    => $site,
                        'url'     => $url,
                        'type'    => $currentType,
                        'context' => $currentContext
                    ];
                }
                $currentWord = trim($matches[1]);
                $currentType = trim($matches[2]);
                $currentContext = null;
                continue;
            }
            if (strpos($line, '上下文:') !== false) {
                $line = ltrim($line);
                if (strpos($line, '上下文:') === 0) {
                    $currentContext = trim(substr($line, strlen('上下文:')));
                    if ($currentWord !== null && $currentContext !== null) {
                        if (!isset($wordMap[$currentWord])) {
                            $wordMap[$currentWord] = ['count' => 0, 'details' => []];
                        }
                        $wordMap[$currentWord]['count']++;
                        $wordMap[$currentWord]['details'][] = [
                            'site'    => $site,
                            'url'     => $url,
                            'type'    => $currentType,
                            'context' => $currentContext
                        ];
                        $currentWord = null;
                        $currentType = null;
                        $currentContext = null;
                    }
                    continue;
                }
            }
        }
        if ($currentWord !== null && $currentContext !== null) {
            if (!isset($wordMap[$currentWord])) {
                $wordMap[$currentWord] = ['count' => 0, 'details' => []];
            }
            $wordMap[$currentWord]['count']++;
            $wordMap[$currentWord]['details'][] = [
                'site'    => $site,
                'url'     => $url,
                'type'    => $currentType,
                'context' => $currentContext
            ];
        }
    }

    // 如果指定了站点过滤，只保留该站点的数据
    if ($siteFilter) {
        foreach ($wordMap as $word => &$info) {
            $info['details'] = array_filter($info['details'], function($d) use ($siteFilter) {
                return $d['site'] === $siteFilter;
            });
            $info['count'] = count($info['details']);
        }
        $wordMap = array_filter($wordMap, function($info) {
            return $info['count'] > 0;
        });
    }

    uasort($wordMap, function($a, $b) {
        return $b['count'] - $a['count'];
    });

    return $wordMap;
}

/**
 * 获取每个站点的已扫描页面数（从 scanned_urls.txt 按主域名后缀统计）
 */
function getSiteScannedPages($scannedFile, $siteDomainMap) {
    if (!file_exists($scannedFile)) return [];
    // 预处理：提取主域名（去掉 www. 和 m. 前缀）
    $siteMainDomains = [];
    foreach ($siteDomainMap as $site => $domain) {
        $mainDomain = $domain;
        if (strpos($mainDomain, 'www.') === 0) {
            $mainDomain = substr($mainDomain, 4);
        } elseif (strpos($mainDomain, 'm.') === 0) {
            $mainDomain = substr($mainDomain, 2);
        }
        $siteMainDomains[$site] = $mainDomain;
    }
    // 按主域名长度降序排序，避免短域名误匹配
    arsort($siteMainDomains);

    $stats = [];
    $handle = fopen($scannedFile, 'r');
    if (!$handle) return [];
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if (empty($line)) continue;
        $parsed = parse_url($line);
        if (isset($parsed['host'])) {
            $host = $parsed['host'];
            // 遍历站点，检查 host 是否以主域名结尾
            foreach ($siteMainDomains as $site => $mainDomain) {
                // 完全相等 或 以 .主域名 结尾（子域名）
                if ($host === $mainDomain || substr($host, -strlen($mainDomain) - 1) === '.' . $mainDomain) {
                    $stats[$site] = ($stats[$site] ?? 0) + 1;
                    break;
                }
            }
        }
    }
    fclose($handle);
    return $stats;
}

/**
 * 获取所有站点列表及基本统计（从 violations_results.txt）
 * 返回数组：[
 *   '站点名' => [
 *       'domain' => '主域名（去掉 www. 和 m.）',
 *       'word_types' => 不同违禁词个数,
 *       'total_issues' => 总违规次数,
 *       'issue_pages' => 违规页面数（URL去重）,
 *   ]
 * ]
 */
function getAllSiteStats($violationsFile) {
    if (!file_exists($violationsFile)) return [];
    $content = file_get_contents($violationsFile);
    $blocks = preg_split('/\n-{76,}\n/', $content);
    $siteData = [];

    foreach ($blocks as $block) {
        $block = trim($block);
        if (empty($block)) continue;
        preg_match('/站点:\s*(.+)/', $block, $m);
        $site = isset($m[1]) ? trim($m[1]) : '未知站点';
        preg_match('/URL:\s*(.+?)(?=\n|$)/', $block, $m);
        $url = isset($m[1]) ? trim($m[1]) : '';

        preg_match_all('/\s+违禁词:\s*(.+?)\s*\(类型:\s*(.+?)\)/s', $block, $wordMatches, PREG_SET_ORDER);
        $words = [];
        foreach ($wordMatches as $wm) {
            $w = trim($wm[1]);
            $words[] = $w;
        }

        if (!isset($siteData[$site])) {
            $siteData[$site] = [
                'domain' => '',
                'word_set' => [],
                'total_issues' => 0,
                'url_set' => []
            ];
        }
        foreach ($words as $w) {
            $siteData[$site]['word_set'][$w] = true;
        }
        $siteData[$site]['total_issues'] += count($words);
        if ($url) {
            $siteData[$site]['url_set'][$url] = true;
        }
        // 提取主域名（去掉 www. 和 m. 前缀）
        if (empty($siteData[$site]['domain']) && $url) {
            $parsed = parse_url($url);
            $host = $parsed['host'] ?? '';
            if (strpos($host, 'www.') === 0) {
                $host = substr($host, 4);
            } elseif (strpos($host, 'm.') === 0) {
                $host = substr($host, 2);
            }
            $siteData[$site]['domain'] = $host;
        }
    }

    $result = [];
    foreach ($siteData as $site => $data) {
        $result[$site] = [
            'domain' => $data['domain'] ?: $site,
            'word_types' => count($data['word_set']),
            'total_issues' => $data['total_issues'],
            'issue_pages' => count($data['url_set'])
        ];
    }
    return $result;
}

// --- 获取基础数据 ---
$now = date('Y-m-d H:i:s');
$scannedTotal = getLineCount($scannedFile);
$violationsTotal = getViolationCount($violationsFile);
$running = isProcessRunning($logFile);
$status = $running ? '扫描中' : ($scannedTotal > 0 ? '已停止' : '未开始');
$currentUrl = getCurrentScanningUrl($logFile);
$logLines = getTailLog($logFile, 30);

$siteStats = getAllSiteStats($violationsFile);
$siteDomainMap = [];
foreach ($siteStats as $site => $info) {
    $siteDomainMap[$site] = $info['domain'];
}
$siteScannedPages = getSiteScannedPages($scannedFile, $siteDomainMap);

// 合并数据，修正状态判断
$logContent = file_get_contents($logFile);
$globalCompleted = strpos($logContent, "🎉 全部扫描完成") !== false;

foreach ($siteStats as $site => &$info) {
    $info['scanned_pages'] = $siteScannedPages[$site] ?? 0;
    if ($info['scanned_pages'] > 0) {
        // 如果全局已完成或日志中有该站点完成标记，则标记为已完成
        if ($globalCompleted || strpos($logContent, "✅ [$site] 完成") !== false) {
            $info['status'] = '已完成';
        } else {
            $info['status'] = '进行中';
        }
    } else {
        $info['status'] = '未开始';
    }
}
unset($info);

$allSiteNames = array_keys($siteStats);
$defaultSite = !empty($allSiteNames) ? $allSiteNames[0] : '';

// --- API 路由 ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $selectedSite = isset($_GET['site']) ? $_GET['site'] : '';
    $wordData = parseViolationsByWord($violationsFile, $selectedSite);

    if ($action === 'export') {
        $export = [];
        foreach ($wordData as $word => $info) {
            $contexts = array_unique(array_column($info['details'], 'context'));
            $export[$word] = array_values($contexts);
        }
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="violations_context.json"');
        echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    if ($action === 'words') {
        header('Content-Type: application/json');
        $list = [];
        foreach ($wordData as $word => $info) {
            $list[] = ['word' => $word, 'count' => $info['count']];
        }
        echo json_encode(['code' => 0, 'data' => $list]);
        exit;
    }

    if ($action === 'detail') {
        header('Content-Type: application/json');
        $word = isset($_GET['word']) ? $_GET['word'] : '';
        if (empty($word)) {
            echo json_encode(['code' => 1, 'msg' => '缺少 word 参数']);
            exit;
        }
        $details = isset($wordData[$word]) ? $wordData[$word]['details'] : [];
        $details = array_values($details);
        echo json_encode(['code' => 0, 'data' => ['word' => $word, 'details' => $details]]);
        exit;
    }

    if ($action === 'sites') {
        header('Content-Type: application/json');
        $sites = [];
        foreach ($siteStats as $name => $info) {
            $sites[] = [
                'name' => $name,
                'domain' => $info['domain'],
                'word_types' => $info['word_types'],
                'total_issues' => $info['total_issues'],
                'scanned_pages' => $info['scanned_pages'],
                'issue_pages' => $info['issue_pages'],
                'status' => $info['status'] ?? '未开始'
            ];
        }
        echo json_encode(['code' => 0, 'data' => $sites]);
        exit;
    }
}

// --- AJAX ---
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'scanned'    => $scannedTotal,
        'violations' => $violationsTotal,
        'running'    => $running,
        'status'     => $status,
        'time'       => $now,
        'currentUrl' => $currentUrl,
        'logs'       => $logLines
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>扫描监控面板</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f4f6fb; padding: 24px; color: #1f2937; }
        .container { max-width: 1440px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; flex-wrap: wrap; gap: 16px; }
        .header h1 { font-size: 26px; font-weight: 600; background: linear-gradient(135deg, #2563eb, #7c3aed); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; letter-spacing: -0.5px; }
        .header-actions { display: flex; align-items: center; gap: 16px; font-size: 14px; color: #6b7280; }
        .header-actions .update-time { background: #fff; padding: 6px 14px; border-radius: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .btn-refresh { background: #fff; border: none; padding: 8px 18px; border-radius: 20px; font-size: 14px; color: #2563eb; font-weight: 500; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.06); transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .btn-refresh:hover { background: #2563eb; color: #fff; box-shadow: 0 4px 12px rgba(37,99,235,0.3); transform: translateY(-1px); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap: 20px; margin-bottom: 28px; }
        .stat-card { background: #fff; border-radius: 16px; padding: 20px 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 4px 12px rgba(0,0,0,0.03); transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.06); }
        .stat-card .label { font-size: 14px; color: #9ca3af; font-weight: 500; text-transform: uppercase; letter-spacing: 0.3px; }
        .stat-card .value { font-size: 34px; font-weight: 700; color: #111827; margin-top: 6px; }
        .stat-card .value.running { color: #059669; }
        .stat-card .value.stopped { color: #dc2626; }
        .stat-card .sub { font-size: 14px; color: #6b7280; margin-top: 4px; word-break: break-all; }
        .log-section { background: #fff; border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); overflow: hidden; margin-bottom: 32px; }
        .log-header { padding: 16px 24px; background: #f8fafc; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; }
        .log-header h3 { font-size: 16px; font-weight: 600; color: #1f2937; }
        .log-header .badge { background: #e5e7eb; color: #4b5563; font-size: 12px; padding: 2px 12px; border-radius: 12px; }
        .log-body { padding: 16px 24px; background: #0d1117; color: #e6edf3; font-family: 'JetBrains Mono', monospace; font-size: 13px; line-height: 1.6; max-height: 400px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; }
        .site-table-wrapper { background: #fff; border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); overflow: hidden; margin-bottom: 24px; border: 1px solid #e5e7eb; }
        .site-table-wrapper table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .site-table-wrapper th { background: #f8fafc; color: #4b5563; font-weight: 600; padding: 12px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
        .site-table-wrapper td { padding: 12px 16px; border-bottom: 1px solid #f1f3f5; vertical-align: middle; }
        .site-table-wrapper tr:last-child td { border-bottom: none; }
        .site-table-wrapper tbody tr { cursor: pointer; transition: background 0.15s; }
        .site-table-wrapper tbody tr:hover { background: #eff6ff; }
        .site-table-wrapper tbody tr.active { background: #dbeafe; border-left: 3px solid #2563eb; }
        .site-table-wrapper .status-badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; }
        .status-badge.running { background: #dbeafe; color: #1e40af; }
        .status-badge.completed { background: #d1fae5; color: #065f46; }
        .status-badge.pending { background: #f3f4f6; color: #6b7280; }
        .violation-section { background: #fff; border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); padding: 24px; margin-bottom: 24px; }
        .violation-section h2 { font-size: 20px; font-weight: 600; color: #1f2937; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .violation-section h2 .count-badge { background: #e5e7eb; font-size: 14px; font-weight: 500; padding: 2px 12px; border-radius: 20px; color: #4b5563; }
        .btn-export { margin-left: auto; background: #10b981; color: #fff; padding: 6px 18px; border-radius: 20px; text-decoration: none; font-size: 14px; font-weight: 500; transition: background 0.2s; }
        .btn-export:hover { background: #059669; }
        .search-box { margin-bottom: 16px; display: flex; gap: 12px; flex-wrap: wrap; }
        .search-box input { flex: 1; min-width: 200px; padding: 10px 16px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s; }
        .search-box input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        .search-box button { padding: 10px 20px; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; transition: background 0.2s; }
        .search-box button:hover { background: #1d4ed8; }
        .word-list { display: flex; flex-wrap: wrap; gap: 12px; max-height: 500px; overflow-y: auto; padding: 4px 0; }
        .word-tag { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #f1f5f9; border-radius: 20px; cursor: pointer; transition: all 0.2s; border: 1px solid transparent; font-size: 14px; color: #1f2937; }
        .word-tag:hover { background: #e0e7ff; border-color: #2563eb; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(37,99,235,0.15); }
        .word-tag .word-text { font-weight: 500; }
        .word-tag .count-badge { background: #2563eb; color: #fff; border-radius: 12px; padding: 0 10px; font-size: 12px; line-height: 20px; font-weight: 500; }
        .no-data { text-align: center; padding: 30px 0; color: #9ca3af; font-size: 16px; width: 100%; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; justify-content: center; align-items: center; padding: 20px; backdrop-filter: blur(2px); }
        .modal-overlay.active { display: flex; }
        .modal { background: #fff; border-radius: 16px; max-width: 900px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 28px 32px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); animation: slideUp 0.3s ease; }
        @keyframes slideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; gap: 12px; }
        .modal-header .modal-title { font-size: 20px; font-weight: 600; color: #1f2937; }
        .modal-close { background: none; border: none; font-size: 24px; color: #9ca3af; cursor: pointer; padding: 0 4px; }
        .modal-close:hover { color: #dc2626; }
        .modal-body .url-detail { margin-bottom: 20px; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
        .modal-body .url-detail .url-header { background: #f8fafc; padding: 10px 14px; border-bottom: 1px solid #e5e7eb; font-weight: 500; }
        .modal-body .url-detail .url-header a { color: #2563eb; text-decoration: none; word-break: break-all; }
        .modal-body .url-detail .url-header a:hover { text-decoration: underline; }
        .modal-body .url-detail .url-header .site-tag { font-weight: normal; color: #6b7280; font-size: 13px; margin-left: 8px; }
        .modal-body .context-entry { padding: 10px 14px; border-bottom: 1px solid #f1f3f5; background: #fefce8; }
        .modal-body .context-entry:last-child { border-bottom: none; }
        .modal-body .context-entry .context-url { font-size: 13px; margin-bottom: 4px; word-break: break-all; }
        .modal-body .context-entry .context-url a { color: #2563eb; text-decoration: none; }
        .modal-body .context-entry .context-url a:hover { text-decoration: underline; }
        .modal-body .context-entry .word-badge { display: inline-block; background: #ef4444; color: #fff; border-radius: 4px; padding: 1px 10px; font-size: 13px; font-weight: 600; margin-right: 6px; }
        .modal-body .context-entry .word-type { font-size: 12px; color: #9ca3af; background: #f3f4f6; padding: 1px 10px; border-radius: 12px; }
        .modal-body .context-entry .context-text { margin-top: 6px; font-size: 14px; color: #1f2937; line-height: 1.7; padding: 6px 10px; background: #fff; border-radius: 4px; }
        .modal-body .context-entry .context-text .highlight { background: #fcd34d; font-weight: 600; padding: 0 3px; border-radius: 3px; }
        .footer { margin-top: 28px; text-align: center; font-size: 13px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 18px; }
        @media (max-width: 640px) { .stats-grid { grid-template-columns: 1fr 1fr; } .log-body { max-height: 250px; font-size: 12px; } .modal { padding: 20px; } .site-table-wrapper table { font-size: 12px; } .site-table-wrapper th, .site-table-wrapper td { padding: 8px; } }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>📊 扫描监控面板</h1>
        <div class="header-actions">
            <span class="update-time">🕒 <span id="updateTime"><?= $now ?></span></span>
            <button class="btn-refresh" onclick="location.reload();">⟳ 刷新</button>
        </div>
    </div>

    <div class="stats-grid" id="stats">
        <div class="stat-card">
            <div class="label">📄 已扫描</div>
            <div class="value" id="scannedCount"><?= $scannedTotal ?></div>
        </div>
        <div class="stat-card">
            <div class="label">⚠️ 违规</div>
            <div class="value" id="violationCount"><?= $violationsTotal ?></div>
        </div>
        <div class="stat-card">
            <div class="label">🔄 状态</div>
            <div class="value <?= $running ? 'running' : 'stopped' ?>" id="runStatus"><?= $running ? '● 运行中' : '● 已停止' ?></div>
            <div class="sub" id="statusDesc"><?= $status ?></div>
        </div>
        <div class="stat-card">
            <div class="label">🔗 当前URL</div>
            <div class="sub" id="currentUrl"><?= htmlspecialchars($currentUrl) ?></div>
        </div>
    </div>

    <div class="log-section">
        <div class="log-header">
            <h3>📋 最新日志（30行）</h3>
            <span class="badge">自动滚动</span>
        </div>
        <div class="log-body" id="logContainer">
            <?php foreach ($logLines as $line): ?>
                <span class="log-line"><?= htmlspecialchars($line) ?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 站点总览表格 -->
    <div class="site-table-wrapper">
        <table id="siteTable">
            <thead>
                <tr>
                    <th>站点名</th>
                    <th>域名</th>
                    <th>违禁词类型</th>
                    <th>问题出现次数</th>
                    <th>已扫描页数</th>
                    <th>问题页面数</th>
                    <th>状态</th>
                </tr>
            </thead>
            <tbody id="siteTableBody">
                <!-- JS 渲染 -->
            </tbody>
        </table>
    </div>

    <!-- 违禁词聚合区域 -->
    <div class="violation-section">
        <h2>
            📌 违禁词聚合
            <span class="count-badge" id="wordCount">加载中...</span>
            <a href="#" id="exportLink" class="btn-export">📥 导出上下文JSON（去重）</a>
        </h2>
        <div class="search-box">
            <input type="text" id="searchInput" placeholder="搜索违禁词..." oninput="filterWords()">
            <button onclick="loadWords()">🔄 刷新</button>
        </div>
        <div class="word-list" id="wordList">
            <div class="no-data">请选择站点查看违禁词</div>
        </div>
    </div>

    <!-- 模态框 -->
    <div class="modal-overlay" id="modalOverlay">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title" id="modalTitle">违禁词详情</div>
                <button class="modal-close" onclick="closeModal()">✕</button>
            </div>
            <div class="modal-body" id="modalBody"></div>
        </div>
    </div>

    <div class="footer">扫描脚本 1.py · 并发100 · 断点续扫 · 点击站点查看违禁词 · 点击违禁词查看详情</div>
</div>

<script>
    let allWords = [];
    let currentSite = '';
    let siteData = [];

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function loadSites() {
        fetch('?action=sites')
            .then(res => res.json())
            .then(data => {
                if (data.code === 0) {
                    siteData = data.data;
                    renderTable(siteData);
                    if (siteData.length > 0) selectSite(siteData[0].name);
                } else console.error('加载站点失败');
            })
            .catch(err => console.error('加载站点失败:', err));
    }

    function renderTable(sites) {
        const tbody = document.getElementById('siteTableBody');
        if (!sites.length) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#9ca3af;">暂无站点数据</td></tr>';
            return;
        }
        let html = '';
        sites.forEach(site => {
            const statusClass = site.status === '已完成' ? 'completed' : (site.status === '进行中' ? 'running' : 'pending');
            const statusText = site.status || '未开始';
            html += `
                <tr data-site="${escapeHtml(site.name)}" onclick="selectSite('${escapeHtml(site.name)}')">
                    <td><strong>${escapeHtml(site.name)}</strong></td>
                    <td>${escapeHtml(site.domain)}</td>
                    <td>${site.word_types}</td>
                    <td>${site.total_issues}</td>
                    <td>${site.scanned_pages}</td>
                    <td>${site.issue_pages}</td>
                    <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
        if (currentSite) {
            const rows = tbody.querySelectorAll('tr');
            rows.forEach(row => {
                if (row.dataset.site === currentSite) row.classList.add('active');
                else row.classList.remove('active');
            });
        }
    }

    function selectSite(siteName) {
        if (!siteName) return;
        currentSite = siteName;
        const rows = document.querySelectorAll('#siteTableBody tr');
        rows.forEach(row => {
            if (row.dataset.site === siteName) row.classList.add('active');
            else row.classList.remove('active');
        });
        document.getElementById('exportLink').href = '?action=export&site=' + encodeURIComponent(siteName);
        loadWords(siteName);
    }

    function loadWords(site) {
        const targetSite = site || currentSite;
        if (!targetSite) {
            document.getElementById('wordList').innerHTML = '<div class="no-data">请选择站点查看违禁词</div>';
            document.getElementById('wordCount').textContent = '0';
            return;
        }
        let url = '?action=words&site=' + encodeURIComponent(targetSite);
        fetch(url)
            .then(res => res.json())
            .then(data => {
                if (data.code === 0) {
                    allWords = data.data;
                    document.getElementById('wordCount').textContent = '共 ' + allWords.length + ' 个';
                    renderWords(allWords);
                } else {
                    document.getElementById('wordList').innerHTML = '<div class="no-data">加载失败</div>';
                }
            })
            .catch(() => {
                document.getElementById('wordList').innerHTML = '<div class="no-data">加载失败，请重试</div>';
            });
    }

    function renderWords(words) {
        const container = document.getElementById('wordList');
        if (!words.length) {
            container.innerHTML = '<div class="no-data">✅ 暂无违禁词记录</div>';
            return;
        }
        let html = '';
        words.forEach(item => {
            html += `
                <div class="word-tag" onclick="showDetail('${encodeURIComponent(item.word)}')">
                    <span class="word-text">${escapeHtml(item.word)}</span>
                    <span class="count-badge">${item.count}</span>
                </div>
            `;
        });
        container.innerHTML = html;
    }

    function filterWords() {
        const keyword = document.getElementById('searchInput').value.toLowerCase();
        if (!keyword) { renderWords(allWords); return; }
        const filtered = allWords.filter(item => item.word.toLowerCase().includes(keyword));
        renderWords(filtered);
    }

    function showDetail(encodedWord) {
        const word = decodeURIComponent(encodedWord);
        if (!currentSite) { alert('请先选择一个站点'); return; }
        let url = '?action=detail&word=' + encodeURIComponent(word) + '&site=' + encodeURIComponent(currentSite);
        fetch(url)
            .then(res => res.json())
            .then(data => {
                if (data.code === 0) {
                    const details = data.data.details;
                    document.getElementById('modalTitle').textContent = '🔍 违禁词：' + word + '（共 ' + details.length + ' 处）';
                    let bodyHtml = '';
                    const urlMap = {};
                    details.forEach(d => {
                        if (!urlMap[d.url]) urlMap[d.url] = { site: d.site, contexts: [] };
                        urlMap[d.url].contexts.push({ type: d.type, context: d.context });
                    });
                    for (const [url, info] of Object.entries(urlMap)) {
                        bodyHtml += `
                            <div class="url-detail">
                                <div class="url-header">
                                    <a href="${escapeHtml(url)}" target="_blank">${escapeHtml(url)}</a>
                                    <span class="site-tag">[${escapeHtml(info.site)}]</span>
                                </div>
                        `;
                        info.contexts.forEach(ctx => {
                            const highlighted = ctx.context.replace(word, `<span class="highlight">${word}</span>`);
                            bodyHtml += `
                                <div class="context-entry">
                                    <div class="context-url">
                                        🔗 <a href="${escapeHtml(url)}" target="_blank">${escapeHtml(url)}</a>
                                    </div>
                                    <span class="word-badge">${escapeHtml(word)}</span>
                                    <span class="word-type">${escapeHtml(ctx.type)}</span>
                                    <div class="context-text">📖 ${highlighted}</div>
                                </div>
                            `;
                        });
                        bodyHtml += `</div>`;
                    }
                    document.getElementById('modalBody').innerHTML = bodyHtml;
                    document.getElementById('modalOverlay').classList.add('active');
                } else {
                    alert('加载详情失败：' + data.msg);
                }
            })
            .catch(() => alert('加载详情失败'));
    }

    function closeModal() {
        document.getElementById('modalOverlay').classList.remove('active');
    }
    document.getElementById('modalOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    function fetchStatus() {
        fetch('?ajax=1')
            .then(res => res.json())
            .then(data => {
                document.getElementById('scannedCount').textContent = data.scanned;
                document.getElementById('violationCount').textContent = data.violations;
                document.getElementById('updateTime').textContent = data.time;
                document.getElementById('currentUrl').textContent = data.currentUrl;
                const statusEl = document.getElementById('runStatus');
                if (data.running) {
                    statusEl.textContent = '● 运行中';
                    statusEl.className = 'value running';
                } else {
                    statusEl.textContent = '● 已停止';
                    statusEl.className = 'value stopped';
                }
                document.getElementById('statusDesc').textContent = data.status;
                const logContainer = document.getElementById('logContainer');
                logContainer.innerHTML = data.logs.map(line => `<span class="log-line">${escapeHtml(line)}</span>`).join('');
                logContainer.scrollTop = logContainer.scrollHeight;
            })
            .catch(() => {});
    }

    window.onload = function() {
        loadSites();
        setInterval(fetchStatus, 2000);
        document.getElementById('logContainer').scrollTop = document.getElementById('logContainer').scrollHeight;
    };
</script>
</body>
</html>