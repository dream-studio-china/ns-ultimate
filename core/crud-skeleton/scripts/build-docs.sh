#!/bin/bash
# Build bilingual docs site: auto-translate → English (site/) + Chinese (site/zh/)
#
# Prerequisites:
#   pip install deep-translator
#
# Usage:
#   bash scripts/build-docs.sh

set -euo pipefail
cd "$(dirname "$0")/.."

echo "=== 1. Translating docs/ → docs-zh/ ==="
python scripts/translate-docs.py

echo "     Copying assets/, research/, ai/, openapi/, javascripts/ → docs-zh/"
rm -rf docs-zh/assets docs-zh/research docs-zh/openapi docs-zh/ai docs-zh/javascripts
cp -r docs/assets docs-zh/assets 2>/dev/null || true
cp -r docs/research docs-zh/research 2>/dev/null || true
cp -r docs/openapi docs-zh/openapi 2>/dev/null || true
cp -r docs/ai docs-zh/ai 2>/dev/null || true
cp -r docs/javascripts docs-zh/javascripts 2>/dev/null || true

echo ""
echo "=== 2. Generating bilingual mkdocs configs ==="
python3 -c "
import yaml, copy
from urllib.parse import urlparse

with open('mkdocs.yml') as f:
    en_cfg = yaml.safe_load(f)

# Extract path from site_url (e.g. /crud-skeleton/ → /crud-skeleton)
site_url = en_cfg.get('site_url', '')
path = urlparse(site_url).path.rstrip('/') if site_url else ''

# --- English config ---
en_cfg.setdefault('extra', {})['alternate'] = [
    {'name': 'English', 'link': path + '/',        'lang': 'en'},
    {'name': '中文',    'link': path + '/zh/',      'lang': 'zh'},
]
with open('mkdocs-en.yml', 'w', encoding='utf-8') as f:
    yaml.dump(en_cfg, f, allow_unicode=True, default_flow_style=False, sort_keys=False)

# --- Chinese config ---
zh_cfg = copy.deepcopy(en_cfg)
zh_cfg['docs_dir'] = 'docs-zh'
zh_cfg['site_dir'] = 'site/zh'
zh_cfg['site_url'] = (site_url.rstrip('/') if site_url else '') + '/zh/'
zh_cfg.setdefault('theme', {})['language'] = 'zh'

# Translate nav labels (static mapping + auto-translate fallback)
MAP = {
    'Home': '首页', 'Design Contracts': '设计契约',
    'System Architecture': '系统架构', 'API Design': 'API 设计',
    'Data Model': '数据模型', 'Module Design': '模块设计',
    'Controller Design': '控制器设计', 'API Documentation': 'API 文档',
    'System Contracts': '系统契约',
    'Bundles': '模块设计', 'Core Framework': 'Core 框架',
    'Common (CMS)': 'Common (CMS)', 'Trade (E-Commerce)': 'Trade (电商)',
    'Payment': 'Payment (支付)', 'Wallet': 'Wallet (钱包)',
    'Identity (Auth)': 'Identity (鉴权)',
    'Research': '调研', 'EasyWeChat 6.x Usage': 'EasyWeChat 6.x 用法',
    'Huifu Payment API': '汇付支付 API', 'AI Context': 'AI 快照',
    'API Reference': 'API 参考',
}
def tr(s):
    if s in MAP: return MAP[s]
    if len(s) < 60 and ' ' in s:
        try:
            from deep_translator import GoogleTranslator
            t = GoogleTranslator(source='en', target='zh-CN')
            r = t.translate(s); MAP[s] = r; return r
        except Exception: pass
    return s
def walk(items):
    for i in items:
        if isinstance(i, dict):
            for k in list(i.keys()):
                nk = tr(k)
                if nk != k: i[nk] = i.pop(k)
                v = i[nk]
                if isinstance(v, list): walk(v)
walk(zh_cfg.get('nav', []))

# Chinese alternate links: same as English (both point to the correct language roots)
zh_cfg.setdefault('extra', {})['alternate'] = [
    {'name': 'English', 'link': path + '/',        'lang': 'en'},
    {'name': '中文',    'link': path + '/zh/',      'lang': 'zh'},
]

with open('mkdocs-zh.yml', 'w', encoding='utf-8') as f:
    yaml.dump(zh_cfg, f, allow_unicode=True, default_flow_style=False, sort_keys=False)
print('     Done.')
"

echo ""
echo "=== 3. Building English site (site/) ==="
mkdocs build -f mkdocs-en.yml --clean

echo ""
echo "=== 4. Building Chinese site (site/zh/) ==="
mkdocs build -f mkdocs-zh.yml --clean

echo ""
echo "=== Done ==="
echo "  English: site/index.html"
echo "  Chinese: site/zh/index.html"
