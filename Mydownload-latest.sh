#!/bin/bash

set -e

# Configuration
REPO_OWNER="IgorPalinchakHub"
REPO_NAME="ctx-mcp-fork"
BINARY_NAME="ctx"
ARCH="arm64"
OS="darwin"

# Determine latest release tag (BSD/macOS-compatible)
echo "🔍 Fetching latest release tag..."
LATEST_TAG=$(curl -s "https://api.github.com/repos/$REPO_OWNER/$REPO_NAME/releases/latest" | sed -n 's/.*"tag_name": "\(.*\)".*/\1/p')

if [ -z "$LATEST_TAG" ]; then
  echo "❌ Failed to determine latest release version."
  exit 1
fi

echo "✅ Latest tag found: $LATEST_TAG"

# Construct download URL
FILE_NAME="$BINARY_NAME-$LATEST_TAG-$OS-$ARCH"
DOWNLOAD_URL="https://github.com/$REPO_OWNER/$REPO_NAME/releases/download/$LATEST_TAG/$FILE_NAME"

# Prepare output directory
OUTPUT_DIR="./.output"
mkdir -p "$OUTPUT_DIR"
TARGET_PATH="$OUTPUT_DIR/ctx-ee"

# Download the binary
echo "⬇️  Downloading from: $DOWNLOAD_URL"
curl -L "$DOWNLOAD_URL" -o "$TARGET_PATH"

# Make it executable
chmod +x "$TARGET_PATH"

# Done
echo "✅ Installed $BINARY_NAME to: $TARGET_PATH"
echo "👉 Run it using: $TARGET_PATH"
