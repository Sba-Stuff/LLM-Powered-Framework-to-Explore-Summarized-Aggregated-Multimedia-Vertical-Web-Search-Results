<?php
function extractSubtopics(string $query, array $titles, array $descriptions, float $delta = 0.5): array {
    $queryTerms = array_filter(explode(' ', strtolower($query)));
    $docs = array_map('strtolower', array_merge($titles, $descriptions));
    $candidateFreq = [];
    $queryFreq = 0;

    // Helper: Extract noun-like phrases (basic n-gram heuristic)
    function extractPhrases(string $text): array {
        preg_match_all('/\b(?:[a-z]+\s){0,3}[a-z]+\b/', $text, $matches);
        return $matches[0];
    }

    // List of trailing stopwords to trim
    $stopwords = ['in', 'on', 'at', 'for', 'of', 'by', 'to', 'with', 'about', 'from'];

    // Normalize a phrase: trim stopwords and collapse whitespace
    function normalizePhrase(string $phrase, array $stopwords): string {
        $phrase = preg_replace('/\b(' . implode('|', $stopwords) . ')\b\s*$/i', '', trim($phrase));
        return preg_replace('/\s+/', ' ', $phrase);
    }

    // Deduplication helper using similarity threshold
    function isSimilar(string $a, string $b, float $threshold = 0.85): bool {
        similar_text($a, $b, $percent);
        return $percent >= ($threshold * 100);
    }

    // Step 1: Collect frequencies
    foreach ($docs as $doc) {
        $phrases = extractPhrases($doc);
        foreach ($phrases as $phrase) {
            $phrase = normalizePhrase($phrase, $stopwords);
            $words = explode(' ', $phrase);
            if (empty($phrase)) continue;

            $containsQueryTerm = false;
            foreach ($queryTerms as $term) {
                if (in_array($term, $words)) {
                    $containsQueryTerm = true;
                    break;
                }
            }

            if ($containsQueryTerm) {
                $candidateFreq[$phrase]['q_and_c'] = ($candidateFreq[$phrase]['q_and_c'] ?? 0) + 1;
            }

            foreach ($queryTerms as $term) {
                if (strpos($doc, $term) !== false) {
                    $queryFreq++;
                    break;
                }
            }

            $nonQuery = array_diff($words, $queryTerms);
            if (!empty($nonQuery)) {
                $candidateFreq[$phrase]['c_minus_q'] = ($candidateFreq[$phrase]['c_minus_q'] ?? 0) + 1;
            }
        }
    }

    // Step 2: Score candidates
    $ranked = [];
    foreach ($candidateFreq as $phrase => $freqs) {
        $fq_c = $freqs['q_and_c'] ?? 0;
        $fq_q = max($queryFreq, 1);
        $fq_cq = max($freqs['c_minus_q'] ?? 1, 1);
        $relatednessScore = ($fq_c / $fq_q) - ($fq_c / $fq_cq);

        if ($relatednessScore <= $delta) {
            $ranked[] = [
                'subtopic' => $phrase,
                'score' => round($relatednessScore, 4),
                'Freq(q,c)' => $fq_c,
                'Freq(q)' => $fq_q,
                'Freq(c\\q)' => $fq_cq,
            ];
        }
    }

    // Step 3: Filter meaningful results (score < 0 and multi-word)
    $filtered = array_filter($ranked, function ($s) {
        return $s['score'] < 0 && str_word_count($s['subtopic']) >= 2;
    });

    // Step 4: Deduplicate similar subtopics
    $unique = [];
    foreach ($filtered as $item) {
        $isDuplicate = false;
        foreach ($unique as $u) {
            if (isSimilar($item['subtopic'], $u['subtopic'])) {
                $isDuplicate = true;
                break;
            }
        }
        if (!$isDuplicate) {
            $unique[] = $item;
        }
    }

    // Step 5: Sort by relatedness score (ascending)
    usort($unique, fn($a, $b) => $a['score'] <=> $b['score']);

    return $unique;
}


/*$query = "harry potter";
$titles = [
    "Harry Potter and the Goblet of Fire Review",
    "Top Magic Spells in the Wizarding World",
    "Behind the scenes of Harry Potter",
];

$descriptions = [
    "Explore every magical moment in Hogwarts.",
    "This film features the Triwizard Tournament in vivid detail.",
    "Fans love Dumbledore’s speech at the Great Hall.",
];

$subtopics = extractSubtopics($query, $titles, $descriptions);

foreach ($subtopics as $s) {
    echo "{$s['subtopic']} (Score: {$s['score']})\n";
}*/
?>