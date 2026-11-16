#!/usr/bin/env python3
"""Draws the Doha skyline once and writes it in the two forms the products need, so they can never drift apart:

  backend/public/brand/qatar-skyline.svg          (dashboard preview + the reference copy)
  storefront/src/assets/qatar-skyline.svg         (web store, byte-identical copy)
  mobile/lib/core/theme/qatar_skyline_paths.dart  (the app paints the same shapes natively, no SVG library needed)

Every shape is a closed outline made only of M / L / H / V / C / Q / Z commands in a 1600 x 340 box, one solid colour.
Shapes are wound clockwise; a seam (glass line) is wound the other way so the non-zero fill rule cuts it out.
Run from the repository root:  python3 scripts/qatar-skyline/build.py
"""
import hashlib
import pathlib
import random

W, H, BASE = 1600, 340, 340
ROOT = pathlib.Path(__file__).resolve().parents[2]

shapes = []  # each shape: list of ops ('M', x, y) ('L', x, y) ('C', x1, y1, x2, y2, x, y) ('Q', x1, y1, x, y) ('Z',)


def poly(points):
    ops = [('M', *points[0])] + [('L', *p) for p in points[1:]] + [('Z',)]
    shapes.append(ops)


def rect(x, y, w, h):
    poly([(x, y + h), (x, y), (x + w, y), (x + w, y + h)])  # clockwise


def seam(x, y, w, h):
    """A thin vertical glass line, wound counter-clockwise so it is cut out of the tower."""
    poly([(x, y), (x, y + h), (x + w, y + h), (x + w, y)])


def path(ops):
    shapes.append(ops)


# ── Landmarks, left to right ───────────────────────────────────────────────────────────────────────────────────────
LANDMARK_RANGES = []


def landmark(x0, x1):
    LANDMARK_RANGES.append((x0, x1))


# Al Bidda Tower: stepped crown and a spire.
landmark(150, 232)
poly([(158, 340), (160, 122), (168, 116), (168, 104), (178, 98), (178, 86), (188, 82), (188, 52), (192, 52), (192, 82),
      (202, 86), (202, 98), (212, 104), (212, 116), (220, 122), (222, 340)])
seam(187, 150, 6, 168)

# Burj Doha: a round shaft under a ribbed dome and a mast.
landmark(338, 422)
path([('M', 344, 340), ('L', 344, 122), ('Q', 344, 68, 380, 62), ('Q', 416, 68, 416, 122), ('L', 416, 340), ('Z',)])
rect(378.5, 36, 3, 30)
for sx in (358, 378.5, 399):
    seam(sx, 130, 3, 188)

# Tornado Tower: the hourglass.
landmark(478, 562)
path([('M', 485, 340), ('C', 501, 300, 503, 262, 503, 230), ('C', 503, 192, 485, 172, 483, 140), ('Q', 483, 118, 520, 114),
      ('Q', 557, 118, 557, 140), ('C', 555, 172, 537, 192, 537, 230), ('C', 537, 262, 539, 300, 555, 340), ('Z',)])
seam(518.5, 130, 3, 188)

# Aspire Tower, the Torch: the tallest, a slim shaft that swells into the flame.
landmark(756, 844)
path([('M', 778, 340), ('C', 786, 300, 789, 252, 789, 214), ('C', 789, 182, 765, 162, 763, 130), ('C', 762, 100, 785, 82, 800, 50),
      ('C', 815, 82, 838, 100, 837, 130), ('C', 835, 162, 811, 182, 811, 214), ('C', 811, 252, 814, 300, 822, 340), ('Z',)])
rect(798.5, 22, 3, 32)
seam(798.5, 98, 3, 222)

# Katara Towers: two blades bending towards each other.
landmark(1012, 1128)
path([('M', 1020, 340), ('L', 1020, 152), ('C', 1020, 114, 1040, 94, 1064, 80), ('C', 1058, 104, 1056, 130, 1056, 160), ('L', 1056, 340), ('Z',)])
path([('M', 1084, 340), ('L', 1084, 160), ('C', 1084, 130, 1082, 104, 1076, 80), ('C', 1100, 94, 1120, 114, 1120, 152), ('L', 1120, 340), ('Z',)])
seam(1036.5, 150, 3, 168)
seam(1100.5, 150, 3, 168)

# Lusail twin towers: two prisms with slanted crowns.
landmark(1234, 1352)
poly([(1240, 340), (1240, 122), (1290, 72), (1290, 340)])
poly([(1296, 340), (1296, 72), (1346, 122), (1346, 340)])
seam(1264, 120, 3, 198)
seam(1319, 120, 3, 198)

# ── The rest of the city: mid-rise towers that frame the landmarks ─────────────────────────────────────────────────
def tower(x, w, top, crown=None):
    pts = [(x, 340), (x, top)]
    if crown == 'step':
        pts += [(x + w * 0.15, top), (x + w * 0.15, top - 14), (x + w * 0.85, top - 14), (x + w * 0.85, top)]
    elif crown == 'point':
        pts += [(x + w / 2, top - 26)]
    elif crown == 'mast':
        pts += [(x + w / 2 - 1.5, top), (x + w / 2 - 1.5, top - 30), (x + w / 2 + 1.5, top - 30), (x + w / 2 + 1.5, top)]
    elif crown == 'slant':
        pts = [(x, 340), (x, top + 18), (x + w, top)]
    pts += [(x + w, top if crown != 'slant' else top), (x + w, 340)]
    poly(pts)


tower(36, 62, 214, 'step')
tower(96, 44, 250)
tower(246, 54, 196, 'mast')
tower(300, 34, 262)
tower(430, 44, 232, 'step')
tower(570, 56, 190, 'point')
tower(628, 42, 242)
tower(676, 52, 210, 'slant')
tower(850, 46, 226, 'step')
tower(900, 40, 258)
tower(944, 54, 206, 'mast')
tower(1132, 44, 240, 'slant')
tower(1180, 46, 216, 'step')
tower(1366, 52, 200, 'mast')
tower(1422, 40, 246)
tower(1466, 60, 216, 'step')
tower(1530, 40, 252)

# Low-rise blocks and a continuous base so the skyline stands on solid ground.
rng = random.Random(7)
x = 0
while x < W:
    w = rng.randint(22, 46)
    h = rng.randint(14, 48)
    if not any(a - 6 <= x + w and x <= b + 6 for a, b in LANDMARK_RANGES):
        rect(x, BASE - h, min(w, W - x), h)
    x += w + rng.randint(0, 6)
rect(0, 322, W, 18)


# ── Output ────────────────────────────────────────────────────────────────────────────────────────────────────────
def num(v):
    s = f'{v:.1f}'.rstrip('0').rstrip('.')
    return s


def svg_d():
    out = []
    for ops in shapes:
        for op in ops:
            c = op[0]
            out.append(c + ' '.join(num(v) for v in op[1:]) if c != 'Z' else 'Z')
    return ''.join(out)


def svg():
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}" role="img" '
            'aria-label="Doha skyline">\n'
            '<title>Doha skyline</title>\n'
            f'<path fill="#000" fill-rule="nonzero" d="{svg_d()}"/>\n</svg>\n')


def dart(digest):
    lines = [
        '// GENERATED by scripts/qatar-skyline/build.py from the Doha skyline drawing. Do not edit by hand.',
        f'// source-sha256: {digest}',
        '',
        "import 'dart:ui';",
        '',
        f'/// Width and height of the skyline drawing; every point below is in this box.',
        f'const double kSkylineWidth = {W};',
        f'const double kSkylineHeight = {H};',
        '',
        '/// The skyline as one closed outline per shape. Fill it non-zero: the glass seams are wound the other way.',
        'Path buildQatarSkylinePath() {',
        '  final p = Path()..fillType = PathFillType.nonZero;',
    ]
    for ops in shapes:
        for op in ops:
            c, a = op[0], [num(v) for v in op[1:]]
            lines.append({
                'M': lambda: f'  p.moveTo({a[0]}, {a[1]});',
                'L': lambda: f'  p.lineTo({a[0]}, {a[1]});',
                'Q': lambda: f'  p.quadraticBezierTo({a[0]}, {a[1]}, {a[2]}, {a[3]});',
                'C': lambda: f'  p.cubicTo({a[0]}, {a[1]}, {a[2]}, {a[3]}, {a[4]}, {a[5]});',
                'Z': lambda: '  p.close();',
            }[c]())
    lines += ['  return p;', '}', '']
    return '\n'.join(lines)


if __name__ == '__main__':
    body = svg()
    digest = hashlib.sha256(body.encode()).hexdigest()
    targets = {
        ROOT / 'backend/public/brand/qatar-skyline.svg': body,
        ROOT / 'storefront/src/assets/qatar-skyline.svg': body,
        ROOT / 'mobile/lib/core/theme/qatar_skyline_paths.dart': dart(digest),
    }
    for p, text in targets.items():
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(text)
        print('wrote', p.relative_to(ROOT))
    print('sha256', digest)
