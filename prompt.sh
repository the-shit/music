#!/usr/bin/env bash
# Simple CLI prompt interface for voice-safe input

echo "============================================"
echo "  Prompt Interface"
echo "============================================"
echo ""
echo "Question: $1"
echo ""

if [[ "$2" == "select" ]]; then
    shift 2
    for i in "$@"; do
        echo "$i"
    done
    echo ""
fi

read -p "Answer: " answer
echo ""
echo "============================================"
echo "Your answer: $answer"
echo "============================================"
