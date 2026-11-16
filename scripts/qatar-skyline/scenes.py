"""The five heritage artworks (besides the Doha skyline): dhow, souq, dunes, museum, arabesque. See art.py for the drawing kit."""
import math
import random

from art import (BASE, H, W, Scene, cubic, ellipse, mirror, move, quad, rot, spline, thick)


# ── shared pieces ──────────────────────────────────────────────────────────────────────────────────────────────────────
def palm(s, x, base, h, lean=0.0, crown=34):
    trunk = spline([(x, base), (x + lean * 0.35, base - h * 0.5), (x + lean, base - h)], 8)
    s.fill(thick(trunk, 9))
    cx, cy = x + lean, base - h
    for k, ang in enumerate([-170, -145, -118, -90, -62, -35, -10, 14, 160]):
        a = math.radians(ang)
        length = crown * (1.3 if k % 2 == 0 else 1.0) + 14
        tip = (cx + length * math.cos(a), cy + length * math.sin(a) + (length * 0.55 if abs(math.cos(a)) > 0.5 else 0))
        mid = (cx + length * 0.55 * math.cos(a), cy + length * 0.55 * math.sin(a) - 12)
        nx, ny = -math.sin(a) * 5, math.cos(a) * 5
        s.fill(quad((cx, cy), (mid[0] + nx, mid[1] + ny), tip, 8) + quad(tip, (mid[0] - nx, mid[1] - ny), (cx, cy), 8))
    s.fill(ellipse(cx, cy + 2, 6, 6, 0, 360, 12))


def dome(s, cx, base, r, h=None):
    h = h or r * 1.05
    s.fill([(cx - r, base)] + cubic((cx - r, base), (cx - r, base - h * 1.3), (cx + r, base - h * 1.3), (cx + r, base), 20))
    s.fill(thick([(cx, base - h * 0.98), (cx, base - h * 1.28)], 3))


def minaret(s, x, base, h, w=16):
    s.rect(x - w / 2, base - h * 0.72, w, h * 0.72)
    s.rect(x - w * 0.85, base - h * 0.74, w * 1.7, 6)
    s.rect(x - w * 0.36, base - h * 0.96, w * 0.72, h * 0.22)
    s.fill([(x - w * 0.62, base - h * 0.96), (x, base - h * 1.12), (x + w * 0.62, base - h * 0.96)])
    s.fill(thick([(x, base - h * 1.1), (x, base - h * 1.2)], 3))
    s.rect_hole(x - 2, base - h * 0.66, 4, h * 0.34)


# ── 1. Dhows on the Gulf ───────────────────────────────────────────────────────────────────────────────────────────────
def dhow_boat(s, x, y, k, flip=False):
    hull = [(0, -30), (8, -34), (26, -30), (150, -26), (214, -30), (246, -42), (268, -72), (277, -72), (267, -36), (246, -10), (214, 4), (50, 4), (14, -2), (2, -16)]
    parts = [hull]
    parts.append([(20, -56), (66, -56), (70, -30), (20, -30)])
    sail = [(60, -40)] + [(205, -205)] + quad((205, -205), (176, -120), (214, -38), 14) + [(60, -40)]
    parts.append(sail)
    parts.append([(222, -40)] + [(264, -138)] + quad((264, -138), (255, -90), (272, -62), 8) + [(224, -38)])
    parts.append(thick([(44, -28), (214, -214)], 4))
    parts.append(thick([(214, -40), (268, -146)], 3))
    parts.append(thick([(150, -28), (150, -60)], 4))
    for p in parts:
        q = move(p, 0, 0, k)
        if flip:
            q = [(-px, py) for px, py in q]
            q = [(px + 277 * k, py) for px, py in q]
        s.fill(move(q, x, y))


def dhow():
    s = Scene('dhow')

    def wave(x, amp=5.0, ph=0.0):
        return 300 + amp * math.sin(x / 55 + ph) + 3 * math.sin(x / 23 + ph * 2)

    top = [(x, wave(x)) for x in range(0, 1601, 8)]
    s.fill(top + [(1600, H), (0, H)])
    for off, ph in ((13, 1.3), (25, 2.6)):
        line = [(x, wave(x, 4.0, ph) + off) for x in range(0, 1601, 8)]
        s.hole(thick(line, 3.2))
    dhow_boat(s, 330, 304, 1.12)
    dhow_boat(s, 1010, 302, 0.82, flip=True)
    dhow_boat(s, 1390, 298, 0.5)
    dhow_boat(s, 690, 296, 0.3, flip=True)
    for bx, by, bs in ((520, 70, 1.0), (580, 46, 0.8), (1180, 80, 1.1), (1250, 52, 0.8), (880, 38, 0.7)):
        s.fill(thick(spline([(bx - 22 * bs, by - 9 * bs), (bx - 11 * bs, by - 1 * bs), (bx, by + 3 * bs)], 6), 2.6))
        s.fill(thick(spline([(bx, by + 3 * bs), (bx + 11 * bs, by - 1 * bs), (bx + 22 * bs, by - 9 * bs)], 6), 2.6))
    return s


# ── 2. Souq Waqif ─────────────────────────────────────────────────────────────────────────────────────────────────────
def souq_house(s, x, w, h, floors=2, beams=True):
    base = 322
    s.rect(x, base - h, w, h)
    n = int((w - 6) // 20)
    for i in range(n):
        s.rect(x + 3 + i * (w - 6) / n + 1, base - h - 9, (w - 6) / n - 4, 9)
    if beams:
        for i in range(int(w // 26)):
            s.rect(x + 8 + i * 26, base - h + 11, 4, 14)
    s.arch_hole(x + w * 0.25, base, 22, 42)
    s.arch_hole(x + w * 0.75, base, 22, 42)
    if h > 96:
        for i in range(2 if w < 120 else 3):
            cxw = x + w * (i + 1) / ((2 if w < 120 else 3) + 1)
            s.rect_hole(cxw - 5, base - h + 34, 10, 22)
    if floors > 2 and h > 140:
        for i in range(2 if w < 120 else 3):
            cxw = x + w * (i + 1) / ((2 if w < 120 else 3) + 1)
            s.rect_hole(cxw - 5, base - h + 70, 10, 22)


def wind_tower(s, x, w, h):
    base = 322
    s.rect(x, base - h, w, h)
    s.fill([(x - 4, base - h), (x + w / 2, base - h - 26), (x + w + 4, base - h)])
    for i in range(3):
        s.rect_hole(x + 6 + i * ((w - 12) / 3) + 1, base - h + 14, (w - 12) / 3 - 4, 44)
    s.arch_hole(x + w / 2, base, 20, 38)


def souq():
    s = Scene('souq')
    s.rect(0, 322, W, 18)
    souq_house(s, 0, 130, 130, 2)
    wind_tower(s, 146, 46, 180)
    souq_house(s, 206, 126, 104, 2)
    souq_house(s, 346, 124, 156, 3)
    souq_house(s, 470, 56, 84, 2, beams=False)
    # the mosque and its two minarets
    minaret(s, 540, 322, 232)
    minaret(s, 750, 322, 232)
    s.rect(562, 252, 166, 70)
    dome(s, 645, 252, 52)
    dome(s, 590, 252, 22)
    dome(s, 700, 252, 22)
    for cx in (600, 645, 690):
        s.arch_hole(cx, 322, 24, 50)
    souq_house(s, 790, 140, 120, 2)
    wind_tower(s, 944, 50, 200)
    souq_house(s, 1010, 132, 140, 3)
    souq_house(s, 1156, 140, 106, 2)
    wind_tower(s, 1312, 46, 168)
    souq_house(s, 1372, 128, 130, 2)
    souq_house(s, 1514, 90, 96, 2)
    return s


# ── 3. Desert dunes: camels, an oryx and a falcon ─────────────────────────────────────────────────────────────────────
def camel(s, x, y, k, flip=False):
    parts = [ellipse(0, -62, 50, 20, 0, 360, 36), ellipse(-6, -88, 18, 16, 0, 360, 24)]
    parts.append(thick(spline([(30, -64), (47, -78), (55, -100), (62, -124)], 6), 16))
    parts.append(rot(ellipse(74, -131, 18, 9.5, 0, 360, 22), 12, 74, -131))
    parts.append(rot(ellipse(91, -126, 8, 6, 0, 360, 16), 18, 91, -126))
    parts.append([(60, -138), (64, -150), (68, -138)])
    for lx, bend in ((34, 3), (22, -2), (-32, -3), (-44, 2)):
        parts.append(thick(spline([(lx, -52), (lx + bend, -26), (lx, 0)], 6), 9))
    parts.append(thick(spline([(-48, -66), (-62, -48), (-60, -30)], 6), 5))
    for p in parts:
        q = move(p, 0, 0, k)
        if flip:
            q = [(-px, py) for px, py in q]
        s.fill(move(q, x, y))


def oryx(s, x, y, k):
    parts = [ellipse(0, -54, 38, 16, 0, 360, 30), thick(spline([(26, -60), (40, -78), (46, -88)], 6), 11), rot(ellipse(52, -91, 12, 5.5, 0, 360, 18), -18, 52, -91)]
    parts.append(thick([(48, -95), (16, -154)], 3.4))
    parts.append(thick([(54, -95), (26, -156)], 3.4))
    for lx in (26, 15, -22, -32):
        parts.append(thick(spline([(lx, -44), (lx + 1, -22), (lx, 0)], 6), 6.5))
    parts.append(thick([(-36, -58), (-46, -42)], 4))
    for p in parts:
        s.fill(move(move(p, 0, 0, k), x, y))


def falcon(s, x, y, k):
    upper = spline([(-4, -3), (-34, -26), (-86, -44), (-136, -40), (-170, -22)], 6)
    lower = spline([(-170, -22), (-130, -20), (-96, -10), (-62, -2), (-30, 4)], 6)
    wing = upper + lower
    parts = [ellipse(0, 0, 26, 7, 0, 360, 24), wing, mirror(wing, 0), [(20, -1), (56, 7), (62, 14), (50, 17), (18, 6)],
             ellipse(28, -3, 8, 5.5, 0, 360, 14), [(34, -5), (45, 0), (33, 2)]]
    for p in parts:
        s.fill(move(rot(move(p, 0, 0, k), -7), x, y))


def ridge_y(curve, x):
    for (x0, y0), (x1, y1) in zip(curve, curve[1:]):
        if x0 <= x <= x1:
            return y0 if x1 == x0 else y0 + (y1 - y0) * (x - x0) / (x1 - x0)
    return curve[-1][1] if x > curve[-1][0] else curve[0][1]


def dunes():
    s = Scene('dunes')
    grid = list(range(0, 1601, 8))

    def sample(pts):
        curve = spline(pts, 14)
        return [(x, ridge_y(curve, x)) for x in grid]

    far = sample([(0, 238), (180, 204), (420, 240), (700, 196), (980, 236), (1250, 200), (1600, 232)])
    mid = sample([(0, 286), (260, 256), (560, 290), (900, 254), (1200, 288), (1600, 262)])
    near = sample([(0, 322), (300, 306), (700, 326), (1100, 308), (1600, 324)])
    s.fill(far + mid[::-1])
    s.fill(mid + near[::-1])
    s.fill(near + [(1600, 340), (0, 340)])
    for curve, drop in ((far, 8), (mid, 8)):
        s.hole(thick([(x, y + drop) for x, y in curve], 2.6))
    for off in (9, 18):
        s.hole(thick([(x, y + off) for x, y in near], 2.2))
    oryx(s, 250, ridge_y(far, 250) + 6, 1.05)
    for cx in (990, 1110, 1230):
        camel(s, cx, ridge_y(far, cx) + 5, 0.8)
    camel(s, 560, ridge_y(mid, 560) + 4, 0.5, flip=True)
    falcon(s, 760, 96, 0.85)
    falcon(s, 1180, 54, 0.5)
    return s


# ── 4. Museum of Islamic Art and the Corniche palms ───────────────────────────────────────────────────────────────────
def museum():
    s = Scene('museum')
    rng = random.Random(3)
    base = 322
    for px, h, lean in ((90, 150, 14), (170, 190, -10), (250, 130, 18), (340, 176, 8), (430, 140, -12), (520, 110, 10)):
        palm(s, px, base, h, lean, 30)
    for px, h, lean in ((1080, 110, -10), (1170, 142, 12), (1260, 176, -8), (1350, 130, 16), (1440, 188, -12), (1520, 150, 10)):
        palm(s, px, base, h, lean, 30)
    cx = 800
    s.rect(cx - 250, 266, 500, base - 266)
    s.rect(cx - 210, 232, 130, 34)
    s.rect(cx + 80, 232, 130, 34)
    s.rect(cx - 130, 196, 260, 70)
    s.rect(cx - 92, 138, 184, 58)
    s.rect(cx - 58, 90, 116, 48)
    s.rect(cx - 30, 52, 60, 38)
    s.rect(cx - 36, 44, 72, 8)
    s.rect(cx - 14, 26, 28, 18)
    for tx, ty, tw in ((cx - 130, 196, 260), (cx - 92, 138, 184), (cx - 58, 90, 116), (cx - 250, 266, 500)):
        for i in range(int(tw // 22)):
            s.rect(tx + 4 + i * 22, ty - 7, 10, 7)
    s.fill([(cx - 250, 266), (cx - 210, 232), (cx - 210, 266)])
    s.fill([(cx + 250, 266), (cx + 210, 232), (cx + 210, 266)])
    for dx in (-90, 0, 90):
        s.rect_hole(cx + dx - 6, 206, 12, 44)
    for dx in (-52, 52):
        s.rect_hole(cx + dx - 5, 148, 10, 36)
    s.rect_hole(cx - 5, 100, 10, 28)
    s.rect_hole(cx - 4, 60, 8, 20)
    for dx in (-200, -160, -120, 120, 160, 200):
        s.rect_hole(cx + dx - 5, 276, 10, 30)
    for dx in (-70, -30, 30, 70):
        s.rect_hole(cx + dx - 5, 276, 10, 30)
    s.arch_hole(cx, base, 46, 70)
    s.rect(0, base, W, H - base)
    for y, ph in ((328, 0.0), (335, 1.6)):
        line = [(x, y + 1.6 * math.sin(x / 38 + ph)) for x in range(0, 1601, 8)]
        s.hole(thick(line, 2.2))
    return s


# ── 5. Islamic geometry: an eight-point star pattern, one 200 px cell that repeats ────────────────────────────────────
def star(cx, cy, big, small, n=8, turn=0.0):
    pts = []
    for i in range(n * 2):
        r = big if i % 2 == 0 else small
        a = math.radians(turn + i * 180 / n - 90)
        pts.append((cx + r * math.cos(a), cy + r * math.sin(a)))
    return pts


def arabesque():
    s = Scene('arabesque', 200, 200, tile=True)
    s.fill(star(100, 100, 84, 50))
    s.hole(star(100, 100, 74, 40))
    s.fill(star(100, 100, 36, 20))
    s.hole(star(100, 100, 26, 14))
    s.fill(star(100, 100, 12, 12, 4, 45))
    for cx, cy in ((0, 0), (200, 0), (0, 200), (200, 200)):
        s.fill([(cx, cy - 34), (cx + 34, cy), (cx, cy + 34), (cx - 34, cy)])
        s.hole([(cx, cy - 25), (cx + 25, cy), (cx, cy + 25), (cx - 25, cy)])
        s.fill([(cx, cy - 12), (cx + 12, cy), (cx, cy + 12), (cx - 12, cy)])
    for cx, cy, horizontal in ((100, 0, False), (100, 200, False), (0, 100, True), (200, 100, True)):
        if horizontal:
            s.fill([(cx - 22, cy), (cx, cy - 8), (cx + 22, cy), (cx, cy + 8)])
        else:
            s.fill([(cx, cy - 22), (cx + 8, cy), (cx, cy + 22), (cx - 8, cy)])
    return s


SCENES = {'dhow': dhow, 'souq': souq, 'dunes': dunes, 'museum': museum, 'arabesque': arabesque}
