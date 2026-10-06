#!/usr/bin/env bash
# Interactive question interface - displays question and reads answer

echo "============================================"
echo "  Question Interface"
echo "============================================"
echo ""
echo "Question: $1"
echo ""

if [[ "$2" == "confirm" ]]; then
    read -p "Continue? [y/N]: " answer
    echo "$answer"
elif [[ "$2" == "select" ]]; then
    echo "Options:"
    shift 2
    for i in "$@"; do
        echo "  $i"
    done
    read -p "Choice: " answer
    echo "$answer"
else
    read -p "Your answer: " answer
    echo "$answer"
fi
