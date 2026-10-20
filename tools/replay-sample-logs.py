#!/usr/bin/env python3
"""
replay-sample-logs.py — 把樣本日誌以 GELF 送進「測試用」Graylog，驗證 IOC 欄位標準化規則

每筆都會加上 ioc_demo=true，讓 IOC 入口與報表能把它和正式資料分開（規格 IS-054～056：
展示／測試資料不得混進正式資料、不得冒充正式結果）。為避免誤打正式環境，--target 必須明確指定，沒有預設值。

用法：
  python3 tools/replay-sample-logs.py --target 10.0.0.5:12201 [--proto udp|tcp] [--show-expect]

之後到 Graylog 搜尋 ioc_demo:true，對照 --show-expect 印出的預期欄位。
清除測試資料：在 Graylog 搜尋 ioc_demo:true 確認筆數後，用該 index set 的輪替／刪除處理，不要直接刪正式索引。
GELF 沒有 extractor：防火牆 src/dst/act 在正式環境由 Syslog 514 Input 的 extractor 產生，樣本已預先帶好。
"""
import argparse
import gzip
import json
import socket
import sys
import time
from pathlib import Path

DEFAULT_FILE = Path(__file__).resolve().parent.parent / "tests" / "fixtures" / "sample-logs.jsonl"
GELF_STANDARD = {"version", "host", "short_message", "full_message", "timestamp", "level", "facility"}


def load_samples(path):
    samples = []
    with open(path, encoding="utf-8") as f:
        for n, line in enumerate(f, 1):
            line = line.strip()
            if line and not line.startswith("#"):
                try:
                    samples.append(json.loads(line))
                except ValueError as e:
                    raise SystemExit("%s 第 %d 行不是合法 JSON：%s" % (path, n, e))
    return samples


def to_gelf(rec, now):
    """標準欄位照 GELF 規格；其餘自訂欄位加底線前綴；expect 只給人看，不送出。"""
    rec = {k: v for k, v in rec.items() if k != "expect"}
    out = {"version": "1.1", "host": rec.pop("host", "ioc-replay"),
           "short_message": rec.pop("short_message", "ioc replay sample"), "timestamp": now}
    for k, v in rec.items():
        out[k if k in GELF_STANDARD else "_" + k] = v
    out["_ioc_demo"] = "true"
    return out


def send(msgs, host, port, proto, delay=0.0):
    if proto == "tcp":
        with socket.create_connection((host, port), timeout=10) as s:
            for m in msgs:
                s.sendall(json.dumps(m, ensure_ascii=False).encode("utf-8") + b"\0")  # GELF TCP 以 \0 分隔
                time.sleep(delay)
    else:
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
            for m in msgs:
                s.sendto(gzip.compress(json.dumps(m, ensure_ascii=False).encode("utf-8")), (host, port))
                time.sleep(delay)


def show_expect(samples):
    for i, rec in enumerate(samples, 1):
        exp = rec.get("expect", {})
        note = exp.get("note")
        fields = ", ".join("%s=%s" % (k, "（不應存在）" if v is None else v) for k, v in exp.items() if k != "note")
        print("%2d. [%s] %s" % (i, rec.get("host", "?"), (rec.get("short_message") or "")[:60]))
        print("    預期：%s" % (fields or "（無）"))
        if note:
            print("    備註：%s" % note)


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.strip().split("\n")[0])
    ap.add_argument("--target", required=True, help="測試用 Graylog 的 GELF Input，host:port")
    ap.add_argument("--proto", choices=("udp", "tcp"), default="udp")
    ap.add_argument("--file", default=str(DEFAULT_FILE))
    ap.add_argument("--delay", type=float, default=0.05, help="每筆間隔秒數")
    ap.add_argument("--show-expect", action="store_true", help="印出每筆的預期標準欄位，供對照 Graylog 搜尋結果")
    args = ap.parse_args(argv)

    host, sep, port = args.target.rpartition(":")
    if not sep or not host or not port.isdigit():
        ap.error("--target 格式為 host:port")
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8")

    samples = load_samples(args.file)
    now = time.time()
    send([to_gelf(r, now) for r in samples], host, int(port), args.proto, args.delay)
    print("已送出 %d 筆（ioc_demo=true）到 %s（%s）。到 Graylog 搜尋 ioc_demo:true 查看。"
          % (len(samples), args.target, args.proto))
    if args.show_expect:
        print()
        show_expect(samples)
    return 0


if __name__ == "__main__":
    sys.exit(main())
