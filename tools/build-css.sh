#!/bin/sh
# Builds assets/css/site.css from the source files (one stylesheet keeps pages at ≤ 3 CSS files, TECH-37).
# CI fails if site.css is out of date: run this after editing any source CSS.
set -e
cd "$(dirname "$0")/.."
{
	echo "/* Built by tools/build-css.sh from tokens.css, base.css, layout.css, components.css. Do not edit. */"
	cat assets/css/tokens.css assets/css/base.css assets/css/layout.css assets/css/components.css
} > assets/css/site.css
