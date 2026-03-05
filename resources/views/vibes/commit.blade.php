@php
    $typeHtml = '';
    if (preg_match('/^(feat|fix|test|ci|refactor|docs|chore|style|perf)[\(:]/', $commit['subject'], $typeMatch)) {
        $type = $typeMatch[1];
        $cssClass = match ($type) {
            'feat' => 'type-feat',
            'fix' => 'type-fix',
            'test' => 'type-test',
            'ci' => 'type-ci',
            'refactor' => 'type-refactor',
            'docs' => 'type-docs',
            'chore' => 'type-chore',
            default => 'type-feat',
        };
        $typeHtml = "<span class=\"commit-type {$cssClass}\">{$type}</span>";
    }
    $hash = htmlspecialchars($commit['short']);
    $subject = htmlspecialchars($commit['subject']);
    $date = date('M j, Y', strtotime($commit['date']));
    $author = htmlspecialchars($commit['author']);
@endphp

<div class="commit">
    <code class="commit-hash">{{ $hash }}</code>
    <div class="commit-info">
        <div class="commit-subject">{!! $typeHtml !!}{{ $subject }}</div>
        <div class="commit-meta">{{ $author }} · {{ $date }}</div>
    </div>
</div>