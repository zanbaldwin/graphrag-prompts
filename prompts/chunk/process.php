<?php declare(strict_types=1);

const MAX_TOKENS = 600;
const TOKENS_PER_WORD = 1.3;
const SCORE_LOW = 0.70;
const SCORE_HIGH = 0.85;

function fetchSemanticSimilarityScore(string $text1, string $text2): float
{
    static $prompt = json_decode(file_get_contents(__DIR__ . '/prompt.json'), true);
    $prompt['prompt'] = sprintf("Text 1:\n%s\n\nText 2:\n%s", preg_replace("{\\n+}", "\n", $text1), preg_replace("{\\n+}", "\n", $text2));
    $context = stream_context_create(['http' => [
        'header' => "Content-type: application/json\r\n",
        'method' => 'POST',
        'content' => json_encode($prompt),
    ]]);
    $result = file_get_contents('https://2v9d51inog7u2p-11434.proxy.runpod.net/api/generate', false, $context);
    $response = json_decode(json_decode($result, true)['response'] ?? 'null', true);
    return $response['semantic-similarity'] ?? throw new \Exception('No score');
}

function getThresholdScore(string $text1, string $text2): float
{
    static $maxWords = round(MAX_TOKENS / TOKENS_PER_WORD, 0);
    $wordCount = str_word_count($text1 . ' ' . $text2);
    if ($wordCount > $maxWords) {
        throw new \DomainException('Do not calculate semantic similarity score; must chunk.');
    }
    $percent = $wordCount / $maxWords;
    return SCORE_LOW + ($percent * abs(SCORE_HIGH - SCORE_LOW));
}

$propositions = json_decode(file_get_contents(__DIR__ . '/../decompose/result.json'), true)['propositions']
    ?? throw new \Exception('Bad Data File');
if (!is_array($propositions)) {
    throw new \Exception('Bad Data');
}
if (count($propositions) < 2) {
    throw new \Exception('Not enough propositions.');
}
$text1 = array_shift($propositions);
$text2 = array_shift($propositions);

/** @return array{string, string} */
$getUserInputForNextUnit = function ($text1, $text2) use (&$propositions): array
{
    $text1 .= "\n" . $text2;
    $text2 = array_shift($propositions);
    return [$text1, $text2];
};

$chunks = [];



do {
    try {
        $threshold = getThresholdScore($text1, $text2);
        $score = fetchSemanticSimilarityScore($text1, $text2);
        if ($threshold > $score) {
            throw new \DomainException('Not semantically similar, chunk.');
        }
        // Add text 2 to text 1 and continue.
        [$text1, $text2] = $getUserInputForNextUnit($text1, $text2);
        echo sprintf('Threshold: %.2f; Score: %.2f; Combine.', $threshold, $score) . PHP_EOL;
    } catch (\DomainException $e) {
        // Chunk.
        $chunks[] = trim($text1);
        [$text1, $text2] = $getUserInputForNextUnit('', $text2);
        echo sprintf('Threshold: %.2f; Score: %.2f; Chunk.', $threshold ?? 100.0, $score ?? 0.0) . PHP_EOL;
    }
} while (count($propositions) > 0);
try {
    $threshold = getThresholdScore($text1, $text2);
    $score = fetchSemanticSimilarityScore($text1, $text2);
    if ($threshold > $score) {
        throw new \DomainException('Not semantically similar, chunk.');
    }
    // Add text 2 to text 1 and continue.
    [$text1, $text2] = $getUserInputForNextUnit($text1, $text2);
    echo sprintf('Threshold: %.2f; Score: %.2f; Combine.', $threshold, $score) . PHP_EOL;
} catch (\DomainException $e) {
    // Chunk.
    $chunks[] = trim($text1);
    [$text1, $text2] = $getUserInputForNextUnit('', $text2);
    echo sprintf('Threshold: %.2f; Score: %.2f; Chunk.', $threshold ?? 100.0, $score ?? 0.0) . PHP_EOL;
}

$chunks[] = trim($text1);
$chunks = array_filter($chunks, fn ($chunk): bool => '' !== trim($chunk ?? ''));

echo '---' . PHP_EOL;
echo json_encode($chunks, JSON_PRETTY_PRINT) . PHP_EOL;
