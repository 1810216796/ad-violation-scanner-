# ad-violation-scanner-
一套全自动的网站广告违禁词检测工具，包含 Python 爬虫扫描、Shell 进程守护、PHP 实时监控面板。

---

# 网站广告违禁词扫描与自动替换系统

本系统提供一套完整的网站广告违禁词检测、过滤和替换方案，包含：

- **Python 扫描器**：递归扫描网站页面，检测违禁词并生成报告。
- **实时监控面板**：PHP 页面实时展示扫描进度、违规统计。
- **宝塔防火墙词库更新**：自动将违禁词替换规则写入宝塔防火墙配置，实现访问时实时替换。
- **进程守护与定时任务**：确保扫描器持续运行，每日自动全量重置。

---

## 📁 文件说明

| 文件 | 作用 |
|------|------|
| `sacn_site.py` | 核心扫描脚本，支持增量/全量扫描，输出违规报告 (`index.html`) |
| `index.php` | 实时监控面板，显示今日扫描页数、违规数、站点状态、违禁词聚合 |
| `update_ads.py` | 读取 `rules.txt`，更新宝塔防火墙配置，实现违禁词自动替换 |
| `rules.txt` | 替换规则库，格式：`违禁词=替换词`（支持精确匹配替换） |
| `宝塔定时任务.sh` | 创建定时任务的 Shell 脚本（可定时执行扫描或更新防火墙） |
| `scan_monitor.sh` | （可选）进程守护脚本，每分钟检查扫描器是否运行，若卡死则重启 |

---

## ⚙️ 环境要求

- Python 3.6+
- PHP 7.4+（用于监控面板）
- Nginx / Apache（托管 PHP 面板）
- 宝塔面板（如需使用防火墙替换功能）
- 依赖库：
  ```bash
  pip3 install requests beautifulsoup4 lxml
  ```

---

## 🚀 安装与配置

### 1. 上传代码

将所有文件上传到服务器目录，例如 `/www/wwwroot/your-domain/`。

### 2. 配置扫描目标站点

编辑 `sacn_site.py` 中的 `WEBSITES` 列表，替换为你要扫描的网站：

```python
WEBSITES = [
    {"name": "我的站点", "urls": ["https://www.example.com/"]},
    # 可添加多个
]
```

### 3. 配置替换词库（可选）

编辑 `rules.txt`，每行一个替换规则，格式为 `违禁词=替换词`。

例如：

```
最佳=较佳
第一=首先
国家级=知名
```

**注意**：该词库用于宝塔防火墙替换，当用户访问页面时，防火墙会自动将违禁词替换为对应词。

### 4. 配置宝塔防火墙替换（如需）

运行 `update_ads.py`（需 root 权限）：

```bash
sudo python3 update_ads.py
```

该脚本会：
- 备份宝塔防火墙配置文件（`/www/server/btwaf/config.json`）
- 读取 `rules.txt`，更新 `body_character_string` 字段
- 重载 Web 服务使配置生效

**建议**：将此脚本加入定时任务，每日执行，保持词库最新。

### 5. 设置定时任务

使用 `宝塔定时任务.sh` 脚本可快速创建定时任务：

```bash
chmod +x 宝塔定时任务.sh
./宝塔定时任务.sh
```

脚本会询问你要设置哪个任务：
- 选择 `1`：每分钟执行扫描监控（需要 `scan_monitor.sh`）
- 选择 `2`：每日凌晨执行防火墙词库更新（`update_ads.py`）

你也可以手动编辑 crontab：

```bash
# 每分钟监控扫描进程
* * * * * /bin/bash /www/wwwroot/your-domain/scan_monitor.sh >> /www/wwwroot/your-domain/monitor_cron.log 2>&1

# 每日凌晨2点更新防火墙词库
0 2 * * * /usr/bin/python3 /www/wwwroot/your-domain/update_ads.py >> /www/wwwroot/your-domain/update_cron.log 2>&1
```

### 6. 配置 Web 访问监控面板

将 `index.php` 放在网站目录下，确保 PHP 可读写同目录下的 `.txt` 和 `.log` 文件。

如果遇到权限问题，可检查 `.user.ini` 中的 `open_basedir` 设置，确保路径正确。

访问 `http://your-domain/index.php` 即可看到实时监控面板。

---

## 🧠 工作原理

### 扫描器（sacn_site.py）
- 使用多线程（默认 20）并发爬取目标网站所有同域名页面。
- 提取页面可见文本（剔除 `script`、`style`）。
- 通过预编译正则匹配违禁词库（`EXACT_FORBIDDEN_WORDS`）。
- 上下文白名单（`SAFE_CONTEXT_PATTERNS`）避免误判（如“世界级文化遗产”）。
- 实时记录已扫描 URL（`scanned_urls.txt`）和违规详情（`violations_results.txt`）。
- 支持 `--reset` 参数清空历史，全量扫描。

### 监控面板（index.php）
- 通过读取 `scanned_urls.txt` 行数统计今日扫描页数。
- 通过统计 `violations_results.txt` 中 `URL:` 次数计算今日违规数。
- 每秒 AJAX 刷新，展示最新状态和日志。
- 点击站点可查看违禁词聚合，点击词条可查看具体上下文。

### 防火墙词库更新（update_ads.py）
- 读取 `rules.txt` 中的替换映射。
- 合并到宝塔防火墙 `body_character_string` 字段（该字段用于替换响应内容中的关键词）。
- 自动备份原有配置，重载 Web 服务，实现**无感知替换**。

---

## 📊 效果展示

- **监控面板**：实时显示今日扫描页数、违规数、运行状态、当前 URL。
- **违禁词聚合**：以标签云形式展示高频违禁词，点击可查看详细页面和上下文。
- **扫描报告**：扫描完成后自动生成 `index.html`，包含所有违规页面列表。

---

## ⚠️ 注意事项

- **并发数调整**：根据服务器性能修改 `sacn_site.py` 中的 `max_workers`（默认 20），避免过高导致目标网站或本地资源耗尽。
- **超时与重试**：`session.timeout` 和 `Retry` 参数可适当调整，适应网络环境。
- **词库维护**：定期更新 `rules.txt`，补充新违禁词，并检查替换词是否合适。
- **宝塔防火墙版本**：`update_ads.py` 基于宝塔 7.x 版本，其他版本可能略有差异，请谨慎测试。
- **安全性**：监控面板建议设置访问密码或仅限内网访问，避免暴露违规详情。

---

## 🐛 常见问题

### 1. 扫描卡住不动怎么办？
监控脚本会自动检测卡死并重启（如果配置了 `scan_monitor.sh`）。也可手动执行 `pkill -f "python3 sacn_site.py"` 终止进程。

### 2. 防火墙替换不生效？
检查 `rules.txt` 格式是否正确（`=` 前后无多余空格），重载 Web 服务后测试。

### 3. PHP 面板显示“今日扫描 0”？
确保 `scanned_urls.txt` 存在且有内容，检查目录权限（PHP 需可读）。

### 4. 如何只增量扫描不重置？
执行 `python3 sacn_site.py` 即可，默认跳过已扫描 URL。

---

## 📞 作者与支持

- **作者**：红穆
- **QQ**：`1810216796`
- 如有问题或建议，欢迎联系。

---

## 📄 许可证

本项目使用 **MIT License**，可自由使用、修改、分发。
