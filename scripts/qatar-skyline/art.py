"""Drawing kit for the Qatar page-background artworks: every shape is a closed polygon in a 1600 x 340 box (or one pattern cell),
one solid colour. Curves are flattened to short segments here, so the SVG and the Dart paths are plain move/line/close lists and
cannot disagree. Fills are wound clockwise (positive area on screen) and holes counter-clockwise, so the non-zero rule cuts holes out.
"""
import math

W, H, BASE = 1600, 340, 340


def area(pts):
    return sum(pts[i][0] * pts[(i + 1) % len(pts)][1] - pts[(i + 1) % len(pts)][0] * pts[i][1] for i in range(len(pts))) / 2


def cubic(p0, p1, p2, p3, n=14):
    out = []
    for i in range(1, n + 1):
        t = i / n
        u = 1 - t
        out.append((u**3 * p0[0] + 3 * u * u * t * p1[0] + 3 * u * t * t * p2[0] + t**3 * p3[0],
                    u**3 * p0[1] + 3 * u * u * t * p1[1] + 3 * u * t * t * p2[1] + t**3 * p3[1]))
    return out


def quad(p0, p1, p2, n=12):
    out = []
    for i in range(1, n + 1):
        t = i / n
        u = 1 - t
        out.append((u * u * p0[0] + 2 * u * t * p1[0] + t * t * p2[0], u * u * p0[1] + 2 * u * t * p1[1] + t * t * p2[1]))
    return out


def spline(pts, n=10):
    """Smooth open curve through the points (Catmull-Rom), flattened."""
    out = [pts[0]]
    ext = [pts[0]] + list(pts) + [pts[-1]]
    for i in range(1, len(ext) - 2):
        p0, p1, p2, p3 = ext[i - 1], ext[i], ext[i + 1], ext[i + 2]
        c1 = (p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6)
        c2 = (p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6)
        out += cubic(p1, c1, c2, p2, n)
    return out


def ellipse(cx, cy, rx, ry, a0=0, a1=360, n=40):
    return [(cx + rx * math.cos(math.radians(a0 + (a1 - a0) * i / n)), cy + ry * math.sin(math.radians(a0 + (a1 - a0) * i / n))) for i in range(n + 1)]


def rot(pts, deg, cx=0.0, cy=0.0):
    c, s = math.cos(math.radians(deg)), math.sin(math.radians(deg))
    return [(cx + (x - cx) * c - (y - cy) * s, cy + (x - cx) * s + (y - cy) * c) for x, y in pts]


def move(pts, dx, dy, sx=1.0, sy=None):
    sy = sx if sy is None else sy
    return [(dx + x * sx, dy + y * sy) for x, y in pts]


def mirror(pts, cx):
    return [(2 * cx - x, y) for x, y in pts][::-1]


def thick(path, w):
    """A line of constant width (a polygon around an open polyline)."""
    left, right = [], []
    for i, (x, y) in enumerate(path):
        a = path[max(i - 1, 0)]
        b = path[min(i + 1, len(path) - 1)]
        dx, dy = b[0] - a[0], b[1] - a[1]
        d = math.hypot(dx, dy) or 1
        nx, ny = -dy / d * w / 2, dx / d * w / 2
        left.append((x + nx, y + ny))
        right.append((x - nx, y - ny))
    return left + right[::-1]


class Scene:
    def __init__(self, key, width=W, height=H, tile=False):
        self.key, self.width, self.height, self.tile = key, width, height, tile
        self.shapes = []

    def fill(self, pts):
        pts = list(pts)
        if len(pts) > 1 and pts[0] == pts[-1]:
            pts = pts[:-1]
        if area(pts) < 0:
            pts = pts[::-1]
        self.shapes.append(pts)

    def hole(self, pts):
        pts = list(pts)
        if len(pts) > 1 and pts[0] == pts[-1]:
            pts = pts[:-1]
        if area(pts) > 0:
            pts = pts[::-1]
        self.shapes.append(pts)

    def rect(self, x, y, w, h):
        self.fill([(x, y), (x + w, y), (x + w, y + h), (x, y + h)])

    def rect_hole(self, x, y, w, h):
        self.hole([(x, y), (x + w, y), (x + w, y + h), (x, y + h)])

    def arch_hole(self, cx, base, w, h):
        r = w / 2
        self.hole([(cx - r, base)] + ellipse(cx, base - h + r, r, r, 180, 360, 16) + [(cx + r, base)])


def fmt(v):
    return f'{v:.1f}'.rstrip('0').rstrip('.')


def svg_d(scene):
    return ''.join('M' + 'L'.join(f'{fmt(x)} {fmt(y)}' for x, y in s) + 'Z' for s in scene.shapes)


def svg(scene):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {scene.width} {scene.height}" width="{scene.width}" height="{scene.height}" '
            f'role="img" aria-label="Qatar artwork: {scene.key}">\n<title>Qatar artwork: {scene.key}</title>\n'
            f'<path fill="#000" fill-rule="nonzero" d="{svg_d(scene)}"/>\n</svg>\n')
