#!/bin/sh
# Run by PrestaShop's Docker image before it installs the shop (its /tmp/pre-install-scripts/,
# tests/e2e/run.sh mounts this file there), as root.
#
# PrestaShop 9.2's installer renames admin/ to a random name itself (rename(), as www-data). The
# directory comes from a layer of the image: on an overlay file system without redirect_dir (as
# Docker's overlay drivers often run), renaming it fails (EXDEV), and so would the installation.
# Copied into the container's own layer first, it can be renamed. (The image's PS_FOLDER_ADMIN
# renames it before the installer runs, with mv, which copies; 9.2's installer then looks for
# admin-dev/, and fails.)
set -eu
cd /var/www/html
if [ -d admin ]; then
  cp -a admin admin.sattleme-copy
  rm -rf admin
  mv admin.sattleme-copy admin
fi
