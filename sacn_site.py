#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
广告违禁词扫描器 v3.2 - 轻量版（并发10，超时30s，重试1次，每页日志）
用法:
  python3 1.py           # 增量扫描（跳过已扫描URL）
  python3 1.py --reset   # 清空历史，重新扫描全部
"""

import re
import logging
import os
import threading
import signal
import sys
import time
import argparse
from urllib.parse import urljoin, urlparse
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed

import requests
from bs4 import BeautifulSoup
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

# ============================================================
# 信号处理（优雅退出）
# ============================================================
def signal_handler(sig, frame):
    print("\n⚠️ 收到中断信号，正在优雅退出... 已扫描数据已实时保存。")
    sys.exit(0)

signal.signal(signal.SIGINT, signal_handler)
signal.signal(signal.SIGTERM, signal_handler)

# ============================================================
# 一、违禁词库（完整版，已修订）
# ============================================================
EXACT_FORBIDDEN_WORDS = [
    # ===== 绝对化用语（广告法第九条） =====
    "最佳", "最好", "最大", "最低", "最高", "最高级", "最全", "最优",
    "最顶级", "最先进", "最优质", "最权威", "最赚", "最便宜", "最时尚",
    "第一", "全国第一", "全网第一", "销量第一", "唯一", "独一无二",
    "仅此一家", "仅此一次", "顶级", "顶级工艺", "顶尖", "极致", "极品",
    "绝版", "绝无仅有", "史无前例", "万能", "完美", "国家级",
    "国家级（非官方认定）", "世界级", "宇宙级", "首选", "王牌", "销冠",
    "天花板", "鼻祖", "NO.1", "100%", "100%有效", "零风险", "永久",
    "彻底解决", "地表最强", "全网首发",

    # ===== 国家机关/象征（广告法第九条） =====
    "国旗", "国歌", "国徽", "军旗", "军歌", "军徽", "国家机关推荐",

    # ===== 特供/专供类（虚假宣传） =====
    "专供", "特供", "内部特供", "国宴特供", "政府专供", "国家领导人推荐",
    "质量免检", "驰名商标",

    # ===== 医疗功效（普通商品/服务不得宣称） =====
    "治疗", "治愈", "疗效", "康复", "预防", "防癌", "抗癌", "降糖",
    "降脂", "减肥", "增强免疫力", "改善睡眠", "调节内分泌",
    "补肾", "益气", "护肝", "养胃", "美白", "祛斑", "抗衰老", "排毒",
    "根治", "包治百病", "永不反弹", "无毒副作用", "三天见效",
    "抗炎", "药妆", "医学护肤品", "抗菌", "抑菌", "消炎", "活血",
    "解毒", "抗敏", "脱敏", "祛疤", "生发", "止脱", "瘦身",

    # ===== 教育承诺 =====
    "保过", "包过", "包拿证", "一次上岸", "官方指定", "100%录取",
    "保提分", "包上岸", "100%考试通过", "保证获得学位",
    "保证提高多少分", "包工作", "顶级师资",

    # ===== 金融承诺 =====
    "无效退款", "稳赚不赔", "保本保息", "高收益",
    "秒到账", "低风险", "低门槛", "低利率", "无成本", "内幕消息",
    "原始股", "百分百高薪就业", "稳赚", "必涨", "保本",
    "复利翻倍", "数字货币", "虚拟货币", "区块链（非合规）",
    "资金盘", "躺赢", "内部消息",

    # ===== 迷信/玄学 =====
    "风水", "龙脉", "招财进宝", "旺人", "旺财", "化解小人",
    "逢凶化吉", "时来运转", "算命", "占卜", "开光", "运程",

    # ===== 其他违禁 =====
    "淫秽", "色情", "赌博", "暴力", "恐怖", "低俗暗示",

    # ===== 旅游行业 =====
    "零团费", "负团费", "不合理低价游", "低价游", "零元购",
    "零团费购物游", "文旅补贴", "政府补贴", "景区补贴",
    "乡村振兴补贴", "养老惠民补贴", "工会团建补贴", "企业内购福利",
    "社区福利", "准五星", "准四星", "准星级", "豪华",
    "以××为准", "与××同级", "车览", "远观", "途经",
    "纯玩无购物", "专属VIP通道", "优先入园", "全网最低价",
    "川内首选", "口碑榜首", "年龄附加费", "地域附加费", "人头费",
    "综合服务费", "大数据杀熟", "长江野生鱼", "长江野生江鲜",
    "清修", "体验传统文化",

    # ===== 价格/促销类 =====
    "秒杀", "疯抢", "再不抢就没了", "清仓", "万人疯抢",
    "零自费", "纯玩团", "品质保证", "全程无购物", "绝无购物",
    "绝无自费", "一价全包", "超值", "特惠", "钜惠", "震撼价",
    "抄底价", "冰点价", "惊爆价", "跳楼价", "白菜价", "吐血价",
    "亏本甩卖", "限时抢购", "最后机会", "错过今天再等一年",

    # ===== 行业/机构自称 =====
    "领导品牌", "领先上市",
]

# ============================================================
# 二、白名单上下文（这些语境下的违禁词不判违规）
# ============================================================
SAFE_CONTEXT_PATTERNS = [
    re.compile(r'中国最[大高长]的', re.IGNORECASE),
    re.compile(r'亚洲最[大高长]的', re.IGNORECASE),
    re.compile(r'世界最[大高长]的', re.IGNORECASE),
    re.compile(r'全国最[大高长]的', re.IGNORECASE),
    re.compile(r'海拔最[高]的', re.IGNORECASE),
    re.compile(r'面积最[大]的', re.IGNORECASE),
    re.compile(r'人口最[多]的', re.IGNORECASE),
    re.compile(r'规模最[大]的', re.IGNORECASE),
    re.compile(r'长度最[长]的', re.IGNORECASE),
    re.compile(r'全球第[一二三四五六七八九十百千万]+', re.IGNORECASE),
    re.compile(r'全国第[一二三四五六七八九十百千万]+', re.IGNORECASE),
    re.compile(r'历史最[悠久]的', re.IGNORECASE),
    re.compile(r'最早的', re.IGNORECASE),
    re.compile(r'高达\d+%', re.IGNORECASE),
    re.compile(r'占比最[高]', re.IGNORECASE),
    re.compile(r'最[大高长]的[湖泊河流山脉沙漠]', re.IGNORECASE),
    re.compile(r'豪华考斯特', re.IGNORECASE),
    re.compile(r'价格仅供参考', re.IGNORECASE),
    re.compile(r'行程仅供参考', re.IGNORECASE),
    re.compile(r'以上信息仅供参考', re.IGNORECASE),
    re.compile(r'具体以实际为准', re.IGNORECASE),
    re.compile(r'老字号', re.IGNORECASE),
    re.compile(r'中华老字号', re.IGNORECASE),
    re.compile(r'如有高血压', re.IGNORECASE),
    re.compile(r'患有高血压', re.IGNORECASE),
    re.compile(r'高血压患者', re.IGNORECASE),
    re.compile(r'有高血压', re.IGNORECASE),
    re.compile(r'高血压[、，]心脏病', re.IGNORECASE),
    re.compile(r'糖尿病[患者者]', re.IGNORECASE),
    re.compile(r'患有糖尿病', re.IGNORECASE),
    re.compile(r'导游服务费', re.IGNORECASE),
    re.compile(r'免排队', re.IGNORECASE),
    re.compile(r'VIP通道', re.IGNORECASE),
    re.compile(r'纯玩无购物', re.IGNORECASE),
    re.compile(r'全程无购物', re.IGNORECASE),
    re.compile(r'纯玩团', re.IGNORECASE),
    re.compile(r'绝无购物', re.IGNORECASE),
    re.compile(r'一价全包', re.IGNORECASE),
    re.compile(r'优先入园', re.IGNORECASE),
    re.compile(r'途经', re.IGNORECASE),
    re.compile(r'车览', re.IGNORECASE),
    re.compile(r'生发', re.IGNORECASE),
    re.compile(r'恐怖', re.IGNORECASE),
    re.compile(r'世界级[的]?文化遗产', re.IGNORECASE),
    re.compile(r'世界级[的]?自然遗产', re.IGNORECASE),
    re.compile(r'世界级[的]?非遗', re.IGNORECASE),
    re.compile(r'世界级[的]?景区', re.IGNORECASE),
    re.compile(r'世界级[的]?地质公园', re.IGNORECASE),
]

def is_safe_context(text, word):
    for pattern in SAFE_CONTEXT_PATTERNS:
        if pattern.search(text):
            return True
    return False

# ============================================================
# 三、预编译正则（性能优化）
# ============================================================
_SORTED_EXACT = sorted(EXACT_FORBIDDEN_WORDS, key=len, reverse=True)
EXACT_REGEX = re.compile('|'.join(re.escape(w) for w in _SORTED_EXACT), re.IGNORECASE)

# ============================================================
# 四、谐音替换（默认关闭）
# ============================================================
def replace_homophones(text):
    return text

# ============================================================
# 五、网站配置
# ============================================================
WEBSITES = [
    {"name": "zhuxilvyou202601", "urls": ["http://www.zhuxilvyou.com/", "http://m.zhuxilvyou.com/"]},
    {"name": "zhuxibaoche202501", "urls": ["http://www.gsbaoche.com/"]},
    {"name": "zhuxidaoyou202501", "urls": ["http://gsdaoyou.com/", "http://m.gsdaoyou.com/"]},
    {"name": "zhuxihuiyi202501", "urls": ["http://www.gshuiyi.com.cn/"]},
    {"name": "zhuxitubu202502", "urls": ["https://www.dunhuangtubu.cn/"]},
    {"name": "zhuxiyanxue202501", "urls": ["https://www.dunhuangyanxue.com/"]},
    {"name": "zhuxihuwaitubu20260222", "urls": ["https://www.dunhuanggebitubu.com/"]},
    {"name": "zhuxi202503", "urls": ["https://www.dhlvxingshe.com/", "https://m.dhlvxingshe.com/"]},
    {"name": "zhuxi202502", "urls": ["https://www.lzlxs.com.cn/", "https://m.lzlxs.com.cn/"]},
    {"name": "zhuxi202501", "urls": ["https://www.xnlxs.cn/", "https://m.xnlxs.cn/"]},
    {"name": "zhuxidaoyou202502", "urls": ["http://www.dhdynet.com/"]},
    {"name": "zhuxihuiyi202502", "urls": ["https://www.dhhygs.com/"]},
    {"name": "zhuxiyanxue202502", "urls": ["https://www.lzyxgs.com/"]},
    {"name": "zhuxidaoyou202503", "urls": ["http://www.xndynet.com/"]},
    {"name": "zhuxi20232", "urls": ["http://www.xnzcgs.com/"]},
    {"name": "zhangyezhuxi", "urls": ["http://www.zhangyelxs.com/", "http://m.zhangyelxs.com/"]},
    {"name": "zhuxi2024", "urls": ["http://www.jygly.com/"]},
    {"name": "Zhuxilvyou_zhuxi202508", "urls": ["https://www.dhzxw.com/"]},
    {"name": "yinchuanlvxingshe", "urls": ["http://www.yclxs.com.cn/", "http://m.yclxs.com.cn/"]},
    {"name": "gannanlvxingshe612", "urls": ["http://www.gnlxs.com/", "http://m.gnlxs.com/"]},
    {"name": "zhuxidaoyou202603", "urls": ["http://www.maruili.com.cn/", "http://m.maruili.com.cn/"]},
    {"name": "xibeilvyouzixunwang", "urls": ["http://www.asdtrip.com/"]},
    {"name": "zhuxi2023", "urls": ["https://dunhuangweb.maruili.com.cn/"]},
]

# ============================================================
# 六、持久化模块
# ============================================================
SCANNED_FILE = "scanned_urls.txt"
VIOLATIONS_FILE = "violations_results.txt"

file_lock = threading.Lock()
scanned_urls = set()

def load_scanned_urls():
    global scanned_urls
    if os.path.exists(SCANNED_FILE):
        with open(SCANNED_FILE, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if line:
                    scanned_urls.add(line)
        print(f"📂 加载已扫描URL: {len(scanned_urls)} 个")
    else:
        print("📂 未发现历史扫描记录，将全量扫描。")

def save_scanned_url(url):
    clean = urlparse(url)._replace(fragment='').geturl()
    with file_lock:
        if clean in scanned_urls:
            return
        scanned_urls.add(clean)
        with open(SCANNED_FILE, 'a', encoding='utf-8') as f:
            f.write(clean + '\n')

def clear_scanned_records():
    global scanned_urls
    scanned_urls.clear()
    if os.path.exists(SCANNED_FILE):
        os.remove(SCANNED_FILE)
    if os.path.exists(VIOLATIONS_FILE):
        os.remove(VIOLATIONS_FILE)
    print("🗑️ 已清空历史扫描记录。")

def save_violation(site_name, url, items):
    with file_lock:
        with open(VIOLATIONS_FILE, 'a', encoding='utf-8') as f:
            f.write(f"站点: {site_name}\n")
            f.write(f"URL: {url}\n")
            for item in items:
                f.write(f"  违禁词: {item['word']} (类型: {item['type']})\n")
                f.write(f"  上下文: {item['context']}\n")
            f.write("-" * 80 + "\n")

# ============================================================
# 七、核心扫描引擎（并发10，超时30秒，重试1次）
# ============================================================
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(message)s')
logger = logging.getLogger(__name__)

session = requests.Session()
retry = Retry(total=1, read=1, connect=1, backoff_factor=0.5, status_forcelist=[500, 502, 503, 504])
adapter = HTTPAdapter(pool_connections=10, pool_maxsize=10, max_retries=retry)
session.mount('http://', adapter)
session.mount('https://', adapter)
session.headers.update({'User-Agent': 'Mozilla/5.0'})
session.timeout = 30

def extract_visible_text(html_content):
    try:
        soup = BeautifulSoup(html_content, 'lxml')
        for element in soup(["script", "style"]):
            element.decompose()
        text = soup.get_text(separator=' ', strip=True)
        return text
    except Exception as e:
        logger.warning(f"解析HTML失败: {e}")
        return ""

def get_context(text, word, window=30):
    idx = text.lower().find(word.lower())
    if idx == -1:
        return ""
    start = max(0, idx - window)
    end = min(len(text), idx + len(word) + window)
    context = text[start:end]
    highlighted = context.replace(word, f'【{word}】')
    return highlighted

def scan_text_for_violations(text):
    if not text:
        return []
    found = {}
    normalized_text = replace_homophones(text)
    exact_hits = EXACT_REGEX.findall(normalized_text)
    for hit in exact_hits:
        if is_safe_context(text, hit):
            continue
        context = get_context(text, hit)
        if hit not in found:
            found[hit] = (hit, '精确匹配', context)
    return [{'word': w, 'type': t, 'context': c} for w, t, c in found.values()]

def crawl_page(site_name, url):
    parsed = urlparse(url)
    clean_url = parsed._replace(fragment='').geturl()
    if clean_url != url:
        url = clean_url

    try:
        resp = session.get(url, timeout=30)
        resp.encoding = resp.apparent_encoding or 'utf-8'
        raw_html = resp.text
    except Exception as e:
        logger.warning(f"请求失败: {url} - {e}")
        return url, [], set()

    visible_text = extract_visible_text(raw_html)
    found_items = scan_text_for_violations(visible_text)

    new_links = set()
    STATIC_EXTENSIONS = ('.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico', 
                         '.webp', '.mp4', '.pdf', '.doc', '.docx', '.xls', '.xlsx', 
                         '.zip', '.rar', '.tar', '.gz', '.json', '.xml', '.woff', '.woff2',
                         '.ttf', '.eot', '.otf', '.mp3', '.avi', '.mov', '.flv')
    try:
        soup = BeautifulSoup(raw_html, 'lxml')
        for tag in soup.find_all(['a', 'link']):
            href = tag.get('href')
            if href:
                full = urljoin(url, href)
                if urlparse(full).netloc == urlparse(url).netloc:
                    clean_full = urlparse(full)._replace(fragment='').geturl()
                    if clean_full.lower().endswith(STATIC_EXTENSIONS):
                        continue
                    if 'e/action/ListInfo.php' in clean_full:
                        continue
                    new_links.add(clean_full)
    except:
        pass

    # 保存违规记录（如果有）
    if found_items:
        save_violation(site_name, url, found_items)

    # 保存已扫描URL
    save_scanned_url(url)

    # ----- 每个页面都记录日志 -----
    if found_items:
        # 取前3个违禁词显示
        preview = ' | '.join([item['word'] for item in found_items[:3]])
        logger.info(f"  ❗ [{site_name}] {url} (违规: {preview})")
    else:
        logger.info(f"  ✅ [{site_name}] {url} (正常)")
    # ------------------------------

    return url, found_items, new_links

def crawl_and_scan(site_config):
    site_name = site_config['name']
    start_urls = site_config['urls']
    logger.info(f"🚀 [{site_name}] 开始扫描...")

    visited = set()
    to_visit = []
    for u in start_urls:
        clean = urlparse(u)._replace(fragment='').geturl()
        if clean in scanned_urls:
            logger.info(f"  ⏭️ 跳过已扫描: {clean}")
        else:
            to_visit.append(clean)
        visited.add(clean)

    violations = {}
    lock = threading.Lock()

    with ThreadPoolExecutor(max_workers=10) as executor:
        future_to_url = {executor.submit(crawl_page, site_name, u): u for u in to_visit}
        to_visit.clear()

        while future_to_url:
            for future in as_completed(future_to_url):
                url, found_items, new_links = future.result()
                if found_items:
                    with lock:
                        violations[url] = found_items
                if new_links:
                    with lock:
                        for link in new_links:
                            if link not in visited and link not in to_visit and link not in scanned_urls:
                                to_visit.append(link)
                with lock:
                    new_tasks = []
                    while to_visit and len(new_tasks) < 10:
                        u = to_visit.pop(0)
                        if u not in visited and u not in scanned_urls:
                            visited.add(u)
                            new_tasks.append(u)
                for u in new_tasks:
                    future_to_url[executor.submit(crawl_page, site_name, u)] = u
                if not future_to_url and not to_visit:
                    break

    total_pages = len(visited)
    logger.info(f"✅ [{site_name}] 完成，新增扫描 {total_pages} 页，违规 {len(violations)} 页")
    return {'name': site_name, 'total_pages': total_pages, 'violations': violations}

# ============================================================
# 八、生成HTML报告（可选，保留）
# ============================================================
def generate_html_report(all_results, output_path='index.html'):
    now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    total_sites = len(all_results)
    total_pages = sum(r['total_pages'] for r in all_results)
    total_violation_pages = sum(len(r['violations']) for r in all_results)
    all_words = {}
    for r in all_results:
        for items in r['violations'].values():
            for item in items:
                word = item['word']
                all_words[word] = all_words.get(word, 0) + 1

    html = f'''<!DOCTYPE html>
<html lang="zh-CN">
<head><meta charset="UTF-8"><title>广告违禁词扫描报告</title>
<style>
  body {{ font-family: "Microsoft YaHei", sans-serif; background: #f5f7fa; margin: 20px; }}
  .container {{ max-width: 1400px; margin: 0 auto; background: #fff; padding: 25px; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,0.1); }}
  h1 {{ color: #2c3e50; border-bottom: 3px solid #e74c3c; padding-bottom: 10px; }}
  .summary {{ display: flex; gap: 30px; flex-wrap: wrap; background: #ecf0f1; padding: 15px 20px; border-radius: 8px; margin: 20px 0; }}
  .summary-item {{ font-size: 16px; }}
  .summary-item span {{ font-weight: bold; color: #e74c3c; font-size: 20px; }}
  .site-block {{ margin: 30px 0; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }}
  .site-header {{ background: #34495e; color: white; padding: 12px 20px; font-size: 18px; font-weight: bold; }}
  .site-header small {{ font-weight: normal; font-size: 14px; color: #bdc3c7; margin-left: 15px; }}
  table {{ width: 100%; border-collapse: collapse; }}
  th {{ background: #f8f9fa; text-align: left; padding: 10px 15px; border-bottom: 2px solid #dee2e6; }}
  td {{ padding: 10px 15px; border-bottom: 1px solid #eee; vertical-align: top; }}
  .url-col {{ word-break: break-all; max-width: 300px; }}
  .context {{ background: #fef9e7; padding: 4px 8px; border-radius: 4px; font-size: 14px; margin-top: 4px; }}
  .word-badge {{ display: inline-block; background: #e74c3c; color: white; border-radius: 12px; padding: 2px 12px; margin: 2px 5px 2px 0; font-size: 13px; }}
  .footer {{ margin-top: 30px; text-align: center; color: #7f8c8d; font-size: 14px; border-top: 1px solid #ddd; padding-top: 15px; }}
</style>
</head>
<body>
<div class="container"><h1>⚡ 广告违禁词扫描报告</h1>
<p style="color:#555;">生成时间：{now} | 并发10 | 超时30s | 断点续扫 | 已去除锚点 | 过滤静态资源 | 跳过ListInfo.php</p>
<div class="summary">
  <div class="summary-item">📌 站点：<span>{total_sites}</span></div>
  <div class="summary-item">📄 总页数：<span>{total_pages}</span></div>
  <div class="summary-item">⚠️ 违规页：<span>{total_violation_pages}</span></div>
  <div class="summary-item">🔤 违禁词种数：<span>{len(all_words)}</span></div>
</div>'''

    if total_violation_pages == 0:
        html += '<div style="padding:20px;background:#d4edda;border-radius:8px;color:#155724;font-size:18px;">✅ 所有页面均未发现违禁词。</div>'
    else:
        for result in all_results:
            if not result['violations']: continue
            html += f'''<div class="site-block"><div class="site-header">{result['name']} <small>违规：{len(result['violations'])}页</small></div><table><tr><th style="width:25%;">URL</th><th>违禁词详情</th></tr>'''
            for url, items in result['violations'].items():
                details = ''.join([f'''<div style="margin-bottom:6px;"><span class="word-badge">{item['word']}</span><span class="match-type">[{item['type']}]</span><div class="context">📖 {item['context']}</div></div>''' for item in items])
                html += f'<tr><td class="url-col">{url}</td><td>{details}</td></tr>'
            html += '</table></div>'

    html += f'''<div class="footer">违禁词 {len(EXACT_FORBIDDEN_WORDS)} 个 | 白名单 {len(SAFE_CONTEXT_PATTERNS)} 条</div></div></body></html>'''
    with open(output_path, 'w', encoding='utf-8') as f:
        f.write(html)
    print(f"✅ HTML 报告已生成：{output_path}")

# ============================================================
# 九、主程序入口
# ============================================================
def main():
    parser = argparse.ArgumentParser(description='广告违禁词扫描器 v3.2 (轻量版)')
    parser.add_argument('--reset', '-r', action='store_true', help='清空历史记录，重新扫描全部URL')
    args = parser.parse_args()

    total_start = time.time()
    print("=" * 60)
    print("🔥 广告违禁词扫描器 v3.2 (轻量版 - 超时30s, 重试1次, 并发10)")
    print(f"精确词: {len(EXACT_FORBIDDEN_WORDS)} | 白名单: {len(SAFE_CONTEXT_PATTERNS)}")
    print(f"待扫描站点: {len(WEBSITES)} 个 | 并发: 10")
    if args.reset:
        print("🔄 已指定 --reset，将清空历史记录并重新扫描全部。")
    print("=" * 60)

    load_scanned_urls()
    if args.reset:
        clear_scanned_records()
    elif scanned_urls:
        print(f"✅ 检测到历史扫描记录 ({len(scanned_urls)} 个URL)，将跳过已扫描的URL，继续增量扫描。")
    else:
        print("✅ 首次运行，全量扫描。")

    all_results = []
    for idx, site in enumerate(WEBSITES, 1):
        site_start = time.time()
        print(f"\n📍 开始扫描第 {idx}/{len(WEBSITES)} 个站点: {site['name']}")
        result = crawl_and_scan(site)
        site_elapsed = time.time() - site_start
        print(f"⏱️ [{site['name']}] 扫描耗时: {site_elapsed:.2f} 秒 ({site_elapsed/60:.2f} 分钟)")
        all_results.append(result)

    report_start = time.time()
    generate_html_report(all_results, 'index.html')
    report_elapsed = time.time() - report_start
    print(f"⏱️ 生成HTML报告耗时: {report_elapsed:.2f} 秒")

    total_elapsed = time.time() - total_start
    print("\n🎉 全部扫描完成！")
    print(f"⏱️ 总耗时: {total_elapsed:.2f} 秒 ({total_elapsed/60:.2f} 分钟)")
    print(f"📄 已扫描URL记录: {SCANNED_FILE}")
    print(f"📄 违规结果记录: {VIOLATIONS_FILE}")
    print("📊 请打开 index.html 查看详细报告。")

if __name__ == '__main__':
    main()
