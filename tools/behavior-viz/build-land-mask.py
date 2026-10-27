#!/usr/bin/env python3
"""把 Natural Earth 110m 陸地（world-atlas 的 land-110m.json，公有領域）轉成 behavior-3d.html 內嵌的點陣遮罩。

用法：
    curl -sSL -o land-110m.json https://cdn.jsdelivr.net/npm/world-atlas@2.0.2/land-110m.json
    python3 build-land-mask.py land-110m.json            # 印出 base64 遮罩與尺寸
    python3 build-land-mask.py land-110m.json --ascii    # 另外印出 ASCII 地圖，目視檢查

遮罩是 360×180（每格 1 度）的等距圓柱投影位元圖：第 0 列是北緯 90→89，第 0 欄是西經 180→179，
每列由左到右、高位元在前，1＝陸地。以格子中心點判斷，奇偶規則掃描線填色，不需要任何函式庫。
這個檔案只在重新產生遮罩時使用，不會被部署，也不需要網路。
"""
import base64
import json
import sys

W, H = 360, 180


def decode_arcs(topo):
    sx, sy = topo["transform"]["scale"]
    tx, ty = topo["transform"]["translate"]
    arcs = []
    for arc in topo["arcs"]:
        x = y = 0
        pts = []
        for dx, dy in arc:
            x += dx
            y += dy
            pts.append((x * sx + tx, y * sy + ty))
        arcs.append(pts)
    return arcs


def ring_points(arcs, indexes):
    pts = []
    for i in indexes:
        seg = arcs[i] if i >= 0 else list(reversed(arcs[~i]))
        pts.extend(seg if not pts else seg[1:])
    return pts


def polygons(topo):
    arcs = decode_arcs(topo)
    for geom in topo["objects"]["land"]["geometries"]:
        polys = geom["arcs"] if geom["type"] == "MultiPolygon" else [geom["arcs"]]
        for poly in polys:
            yield [ring_points(arcs, ring) for ring in poly]


def rasterize(topo):
    edges_by_row = [[] for _ in range(H)]
    for rings in polygons(topo):
        for ring in rings:
            for (x1, y1), (x2, y2) in zip(ring, ring[1:]):
                if y1 == y2:
                    continue
                lo, hi = (y1, y2) if y1 < y2 else (y2, y1)
                # 行 r 的中心緯度：90 - (r + 0.5)；邊跨過中心緯度才算交點
                r_first = max(0, int((90 - hi) - 0.5) - 1)
                r_last = min(H - 1, int((90 - lo) - 0.5) + 1)
                for r in range(r_first, r_last + 1):
                    lat = 90 - (r + 0.5)
                    if lo <= lat < hi:
                        edges_by_row[r].append(x1 + (lat - y1) * (x2 - x1) / (y2 - y1))
    grid = [[0] * W for _ in range(H)]
    for r in range(H):
        xs = sorted(edges_by_row[r])
        for a, b in zip(xs[0::2], xs[1::2]):
            c0 = max(0, int(a + 180 - 0.5) + (0 if a + 180 - 0.5 <= 0 else 1))
            c1 = min(W - 1, int(b + 180 - 0.5))
            for c in range(c0, c1 + 1):
                if a <= c + 0.5 - 180 < b:
                    grid[r][c] = 1
    return grid


def pack(grid):
    out = bytearray()
    bits = [b for row in grid for b in row]
    for i in range(0, len(bits), 8):
        byte = 0
        for j in range(8):
            byte = (byte << 1) | (bits[i + j] if i + j < len(bits) else 0)
        out.append(byte)
    return bytes(out)


def main():
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    topo = json.load(open(sys.argv[1], encoding="utf-8"))
    grid = rasterize(topo)
    land = sum(map(sum, grid))
    print("尺寸 %dx%d，陸地格 %d（%.1f%%）" % (W, H, land, 100.0 * land / (W * H)), file=sys.stderr)
    if "--ascii" in sys.argv:
        for r in range(0, H, 4):
            print("".join("#" if grid[r][c] else "." for c in range(0, W, 3)), file=sys.stderr)
    print(base64.b64encode(pack(grid)).decode("ascii"))


if __name__ == "__main__":
    main()
