#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import json, os, sys, shutil, subprocess
from datetime import datetime

CONFIG_PATH = "/www/server/btwaf/config.json"
BACKUP_DIR = "/www/server/btwaf/backup"
RULES_FILE = "rules.txt"

def load_rules(filepath):
    rules = {}
    if not os.path.exists(filepath):
        print("词库文件不存在：", filepath)
        sys.exit(1)
    with open(filepath, 'r', encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#'):
                continue
            if '=' in line:
                k, v = line.split('=', 1)
                k, v = k.strip(), v.strip()
                if k and v:
                    rules[k] = v
    return rules

def backup_config():
    if not os.path.exists(CONFIG_PATH):
        print("配置文件不存在")
        return
    os.makedirs(BACKUP_DIR, exist_ok=True)
    ts = datetime.now().strftime('%Y%m%d_%H%M%S')
    bak = os.path.join(BACKUP_DIR, f"config.json.bak_{ts}")
    shutil.copy2(CONFIG_PATH, bak)
    print("备份完成:", bak)

def update_config(new_rules):
    with open(CONFIG_PATH, 'r', encoding='utf-8') as f:
        config = json.load(f)
    field = config.get("body_character_string")
    if field is None:
        field = []
    if not isinstance(field, list):
        if isinstance(field, dict):
            field = [field]
        else:
            print("字段格式异常")
            return False
    existing = {}
    for item in field:
        if isinstance(item, dict):
            existing.update(item)
    existing.update(new_rules)
    new_field = [{k: v} for k, v in existing.items()]
    config["body_character_string"] = new_field
    with open(CONFIG_PATH, 'w', encoding='utf-8') as f:
        json.dump(config, f, ensure_ascii=True, indent=2)
    print("配置文件已更新")
    return True

def reload_webserver():
    try:
        subprocess.run(['nginx', '-t'], check=True, stderr=subprocess.DEVNULL)
        subprocess.run(['nginx', '-s', 'reload'], check=True)
        print("Web服务重载成功 (nginx)")
        return
    except:
        pass
    try:
        subprocess.run(['systemctl', 'reload', 'httpd'], check=True)
        print("Web服务重载成功 (httpd)")
        return
    except:
        pass
    try:
        subprocess.run(['service', 'nginx', 'reload'], check=True)
        print("Web服务重载成功 (service nginx)")
        return
    except:
        pass
    print("请手动重载 Web 服务 (nginx -s reload 或 systemctl reload nginx)")

def main():
    if os.geteuid() != 0:
        print("请使用 root 权限运行")
        sys.exit(1)
    script_dir = os.path.dirname(os.path.abspath(__file__))
    rules_path = os.path.join(script_dir, RULES_FILE)
    rules = load_rules(rules_path)
    if not rules:
        print("词库为空")
        sys.exit(1)
    print("待处理规则：")
    for k, v in rules.items():
        print(f"  {k} -> {v}")
    backup_config()
    if update_config(rules):
        reload_webserver()
        print("全部完成！")
    else:
        print("更新失败")

if __name__ == "__main__":
    main()
