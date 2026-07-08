#!/bin/bash
WORK_DIR="/www/wwwroot/139.196.216.222"
SCAN_CMD="python3 1.py"
LOG_FILE="$WORK_DIR/scan.log"
COUNT_FILE="$WORK_DIR/scanned_urls.txt"
LOCK_FILE="/tmp/scan_monitor.lock"
FULL_SCAN_TIME_FILE="/tmp/full_scan_time.txt"

# 检测超时时间（秒）
STUCK_TIMEOUT=60

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"; }

is_scan_complete() { tail -100 "$LOG_FILE" | grep -q "🎉 全部扫描完成"; }
get_page_count() { [ -f "$COUNT_FILE" ] && wc -l < "$COUNT_FILE" | tr -d ' ' || echo 0; }
is_process_running() { pgrep -f "$SCAN_CMD" > /dev/null 2>&1; }
kill_all_scanners() { pkill -f "$SCAN_CMD" 2>/dev/null; sleep 2; }

# 启动扫描，接受额外参数（如 --reset）
start_scanner() {
    cd "$WORK_DIR" || exit 1
    local extra_args="$1"
    nohup $SCAN_CMD $extra_args > "$LOG_FILE" 2>&1 &
    log "进程已启动 (PID: $!, 参数: $extra_args)"
}

# 锁防并发
if [ -f "$LOCK_FILE" ] && [ -z "$(find "$LOCK_FILE" -mmin +10 2>/dev/null)" ]; then
    log "已有监控进程运行，退出"
    exit 0
fi
trap 'rm -f "$LOCK_FILE"; exit' INT TERM EXIT
echo $$ > "$LOCK_FILE"

log "开始监控扫描..."

# 检查是否需要全量扫描（距离上次全量是否超过24小时）
CURRENT_TIME=$(date +%s)
LAST_FULL_TIME=0
[ -f "$FULL_SCAN_TIME_FILE" ] && LAST_FULL_TIME=$(cat "$FULL_SCAN_TIME_FILE")
TIME_DIFF=$((CURRENT_TIME - LAST_FULL_TIME))

if [ $TIME_DIFF -ge 86400 ]; then
    log "距离上次全量扫描已超过24小时（${TIME_DIFF}秒），执行全量扫描..."
    echo "$CURRENT_TIME" > "$FULL_SCAN_TIME_FILE"
    kill_all_scanners
    start_scanner "--reset"
    rm -f "$LOCK_FILE"
    log "全量扫描已启动，监控退出"
    exit 0
fi

# ---- 增量模式 ----
if is_scan_complete; then
    log "扫描已完成，监控退出"
    rm -f "$LOCK_FILE"
    exit 0
fi

if ! is_process_running; then
    log "扫描进程未运行，启动增量扫描..."
    start_scanner ""
    rm -f "$LOCK_FILE"
    exit 0
fi

# 检查页面数是否增长（防卡死）
PREV_COUNT=0
[ -f "/tmp/scan_count_prev" ] && PREV_COUNT=$(cat /tmp/scan_count_prev)
CURRENT_COUNT=$(get_page_count)
echo "$CURRENT_COUNT" > /tmp/scan_count_prev

if [ "$CURRENT_COUNT" -eq "$PREV_COUNT" ]; then
    LOG_MTIME=$(stat -c %Y "$LOG_FILE" 2>/dev/null || echo 0)
    NOW=$(date +%s)
    TIME_DIFF=$((NOW - LOG_MTIME))
    if [ $TIME_DIFF -gt $STUCK_TIMEOUT ]; then
        log "页面数未增长，且日志 ${TIME_DIFF} 秒未更新，判断为卡住，重启增量扫描..."
        kill_all_scanners
        start_scanner ""
    else
        log "页面数未增长，但日志还在更新（最后更新 ${TIME_DIFF} 秒前），等待中..."
    fi
else
    log "页面数在增长（${PREV_COUNT} -> ${CURRENT_COUNT}），扫描正常"
fi

# 清理多余进程
PROC_COUNT=$(pgrep -c -f "$SCAN_CMD")
if [ "$PROC_COUNT" -gt 1 ]; then
    log "检测到 ${PROC_COUNT} 个扫描进程，清理多余进程..."
    kill_all_scanners
    start_scanner ""
fi

rm -f "$LOCK_FILE"
log "监控检查完成"
exit 0
