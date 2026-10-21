#!/usr/bin/env python3
# (2.3.0) payload/receiver/page.c from page.html and code.html: the phone's pages, as C strings.
import os, sys
d = sys.argv[1]
def cstr(name):
    lines = open(os.path.join(d, name), encoding='utf-8').read().split('\n')
    return '\n'.join('    "' + l.replace('\\', '\\\\').replace('"', '\\"') + '\\n"' for l in lines)
open(os.path.join(d, 'page.c'), 'w').write("/* The phone's page and the code page (page.html, code.html; made by tools/make-receiver-page.py). */\n"
    'const char kPage[] =\n' + cstr('page.html') + ';\nconst char kCodePage[] =\n' + cstr('code.html') + ';\n')
