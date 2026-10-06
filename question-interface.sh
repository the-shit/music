#!/usr/bin/env bash
# Interactive question interface

clear
echo "============================================"
echo "  Question Interface"
echo "============================================"
echo ""
echo "Question: $1"
echo ""
echo "Options:"
if [[ -n "$2" ]]; then
    echo "$2"
fi
echo ""
echo "---"
echo "Waiting for response..."
echo "---"
