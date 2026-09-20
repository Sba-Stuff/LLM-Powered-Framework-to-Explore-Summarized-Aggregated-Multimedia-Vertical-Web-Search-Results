<?php
// searchresults3_integrated.php
// Integrated version with LLM topic generation

// Increase PHP limits
set_time_limit(3600); // 60 minutes
ini_set('memory_limit', '1G');
ini_set('max_execution_time', 3600);
ini_set('default_socket_timeout', 1800);

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

include('debugging.php');

// ============================================
// LLM API FUNCTION (from your code)
// ============================================
function callLMStudio($prompt, $systemMessage = "You are a helpful assistant.", $temperature = 0.7, $maxTokens = 500, $model = 'liquid/lfm2-1.2b') {
    $apiUrl = 'http://127.0.0.1:1234/v1/chat/completions';
    $apiKey = ''; // LM Studio often doesn't need a key
    
    // Limit prompt size
    $prompt = substr($prompt, 0, 6000);
    $systemMessage = substr($systemMessage, 0, 1000);
    
    $payload = [
        "model" => $model,
        "messages" => [
            ["role" => "system", "content" => $systemMessage],
            ["role" => "user", "content" => $prompt]
        ],
        "max_tokens" => $maxTokens,
        "temperature" => $temperature,
        "stream" => false
    ];
    
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_TIMEOUT => 600, // 10 minutes per request
        CURLOPT_CONNECTTIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return [
            'success' => false,
            'error' => "cURL Error: " . $error
        ];
    }
    
    curl_close($ch);
    $apiResponse = json_decode($response, true);
    
    if (!isset($apiResponse['choices'][0]['message']['content'])) {
        return [
            'success' => false,
            'error' => "Unexpected API response",
            'raw' => $apiResponse
        ];
    }
    
    $content = trim($apiResponse['choices'][0]['message']['content']);
    
    if (empty($content)) {
        return [
            'success' => false,
            'error' => "Empty response from LLM"
        ];
    }
    
    return [
        'success' => true,
        'content' => $content
    ];
}

// Simple LLM call with retry
function getLLMResponse($prompt, $systemMessage = "You are a helpful assistant.", $maxRetries = 2) {
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $result = callLMStudio($prompt, $systemMessage, 0.2, 300);
        
        if ($result['success']) {
            return $result['content'];
        }
        
        if ($attempt < $maxRetries) {
            sleep(5); // Wait 5 seconds before retry
        }
    }
    
    return "Error: Failed after $maxRetries attempts. " . ($result['error'] ?? 'Unknown error');
}

// Parse LLM response into structured data
function parseTopicResponse($response) {
    $parsed = [
        'topic' => '',
        'title' => '',
        'description' => '',
        'keywords' => '',
        'raw' => $response
    ];
    
    // Extract components with flexible pattern matching
    $patterns = [
        'topic' => '/TOPIC[:\-]?\s*(.+?)(?=(?:\n\s*\n?TITLE|\n\s*\n?DESCRIPTION|\n\s*\n?KEYWORDS|$))/is',
        'title' => '/TITLE[:\-]?\s*(.+?)(?=(?:\n\s*\n?DESCRIPTION|\n\s*\n?KEYWORDS|$))/is',
        'description' => '/DESCRIPTION[:\-]?\s*(.+?)(?=(?:\n\s*\n?KEYWORDS|$))/is',
        'keywords' => '/KEYWORDS[:\-]?\s*(.+?)$/is'
    ];
    
    foreach ($patterns as $key => $pattern) {
        if (preg_match($pattern, $response, $matches)) {
            $parsed[$key] = trim($matches[1]);
        }
    }
    
    // If parsing failed, use the raw response as topic
    if (empty($parsed['topic']) && !empty($response)) {
        $parsed['topic'] = $response;
    }
    
    return $parsed;
}

// ============================================
// MAIN FUNCTION: Generate LLM Topics
// ============================================
function generateLLMTopicsForEvaluation($query) {
    $encodedQuery = $query;
    @include_once("verticalsfetcher.php");
    @include_once("subtopicminer.php");
    
    if (!function_exists('BingWebResults')) {
        return "<div style='color:red; padding:20px;'>Error: Search functions not available. Check verticalsfetcher.php</div>";
    }
    
    try {
        // Get related topics (similar to original code)
        $relatedSearch = BingRelatedSearches($encodedQuery, false);
        $relatedTopics = array_column($relatedSearch, 'text');
        $suggestions = getBingSuggestions($encodedQuery, false);
        $searchResults = BingWebResults($encodedQuery, 10, false);
        $onlytitles = array_column($searchResults, 'title');
        $onlydescriptions = array_column($searchResults, 'description');
        $subtopicsRaw = extractSubtopics($query, $onlytitles, $onlydescriptions);
        $subtopics = array_column($subtopicsRaw, 'subtopic');
        $allTopics = array_merge($relatedTopics, $suggestions, $subtopics);
        $uniqueTopics = array_values(array_unique($allTopics));
        $rankedTopics = rankTitlesByCosineSimilarity($query, $uniqueTopics);
        
        // Limit to 10 topics for testing (you can increase to 31 later)
        $topicsToUse = array_slice($rankedTopics, 0, 1);
        $output = "";
        $processedCount = 0;
        
        echo "<div class='progress-info' style='padding:20px; background:#f0f0f0; margin-bottom:20px;'>";
        echo "<h3>Generating LLM Topics (0/" . count($topicsToUse) . ")</h3>";
        echo "<div id='progress-console' style='border:1px solid #ccc; padding:10px; height:200px; overflow:auto; background:#fff;'></div>";
        echo "</div>";
        
        ob_flush();
        flush();
        
        foreach ($topicsToUse as $index => $topicData) {
            $topic = $topicData['title'];
            if (empty($topic)) continue;
            
            $pageNumber = $index + 1;
            logProgress("Processing topic $pageNumber: " . substr($topic, 0, 50) . "...");
            
            // Get search results for this topic
            $webResults = BingWebResults($topic, 3, false);
            $videos = scrapeBingVideoResults($topic);
            $images = BingImageResults($topic);
            
            if (empty($webResults) && empty($videos) && empty($images)) {
                logProgress("‚ö†Ô∏è No results for: $topic - Skipping");
                continue;
            }
            
            // Prepare metadata for LLM
            $metadata = prepareMetadataForLLM($webResults, $images, $videos);
            
            // Generate LLM prompt
            $llmPrompt = createTopicGenerationPrompt($metadata, $topic);
            
            // Get LLM response
            logProgress("‚ö° Calling LLM for: $topic");
            $llmResponse = getLLMResponse($llmPrompt, "You are an expert at analyzing search results.");
            
            // Parse response
            $parsed = parseTopicResponse($llmResponse);
            
            // Create evaluation page
            $output .= createEvaluationPage($topic, $pageNumber, $webResults, $images, $videos, $parsed);
            
            $processedCount++;
            logProgress("‚úÖ Completed: $topic");
            
            // Add delay between LLM calls to avoid overwhelming
            if ($pageNumber < count($topicsToUse)) {
                sleep(10); // 10 second delay
            }
        }
        
        logProgress("Ì†ºÌæâ Finished! Processed $processedCount topics.");
        
        return $output;
        
    } catch (Exception $e) {
        return "<div style='color:red; padding:20px;'>Error: " . $e->getMessage() . "</div>";
    }
}

// Helper: Prepare metadata for LLM
function prepareMetadataForLLM($webResults, $images, $videos) {
    $metadata = "";
    
    // Web Results
    $metadata .= "WEB SEARCH RESULTS:\n";
    if (!empty($webResults)) {
        foreach ($webResults as $i => $web) {
            $metadata .= ($i+1) . ". " . $web['title'] . "\n";
            $metadata .= "   " . substr($web['description'], 0, 150) . "\n\n";
        }
    }
    
    // Image Results (limit to 3 for metadata)
    $metadata .= "IMAGE SEARCH RESULTS:\n";
    if (!empty($images)) {
        for ($i = 0; $i < min(3, count($images)); $i++) {
            $img = $images[$i];
            $metadata .= ($i+1) . ". " . $img['title'] . "\n";
            if (!empty($img['description'])) {
                $metadata .= "   " . substr($img['description'], 0, 100) . "\n";
            }
            $metadata .= "\n";
        }
    }
    
    // Video Results (limit to 3 for metadata)
    $metadata .= "VIDEO SEARCH RESULTS:\n";
    if (!empty($videos)) {
        for ($i = 0; $i < min(3, count($videos)); $i++) {
            $video = $videos[$i];
            $metadata .= ($i+1) . ". " . $video['title'] . "\n";
            if (!empty($video['description'])) {
                $metadata .= "   " . substr($video['description'], 0, 100) . "\n";
            }
            $metadata .= "\n";
        }
    }
    
    return $metadata;
}

// Helper: Create LLM prompt
function createTopicGenerationPrompt($metadata, $query) {
    $prompt = "Based on the following search results for: \"$query\"\n\n";
    $prompt .= $metadata . "\n\n";
    $prompt .= "Please create:\n\n";
    $prompt .= "1. TOPIC: A concise main topic (1-2 sentences)\n";
    $prompt .= "2. TITLE: A descriptive title (5-10 words)\n";
    $prompt .= "3. DESCRIPTION: A comprehensive summary (2-3 paragraphs)\n";
    $prompt .= "4. KEYWORDS: 3-5 relevant keywords\n\n";
    $prompt .= "Format your response exactly as:\n";
    $prompt .= "TOPIC: [your topic here]\n";
    $prompt .= "TITLE: [your title here]\n";
    $prompt .= "DESCRIPTION: [your description here]\n";
    $prompt .= "KEYWORDS: [keyword1, keyword2, keyword3]";
    
    return $prompt;
}

// Helper: Log progress to console
function logProgress($message) {
    $timestamp = date('H:i:s');
    echo "<script>
        var console = document.getElementById('progress-console');
        if (console) {
            console.innerHTML += '<div style=\"padding:3px; border-bottom:1px solid #eee;\"><span style=\"color:#666;\">[$timestamp]</span> ' + " . json_encode($message) . " + '</div>';
            console.scrollTop = console.scrollHeight;
        }
    </script>";
    ob_flush();
    flush();
}

// Helper: Create evaluation page
function createEvaluationPage($topic, $pageNumber, $webResults, $images, $videos, $llmData) {
    $page = "<div class='evaluation-page' style='page-break-after: always; padding: 20px; margin-bottom: 30px; border: 2px solid #ccc;'>";
    
    // Header
    $page .= "<div style='border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 20px;'>";
    $page .= "<div style='float: right; color: #666;'>Page $pageNumber</div>";
    $page .= "<h2 style='margin: 0;'>LLM-Generated Topic Evaluation</h2>";
    $page .= "<p style='margin: 5px 0 0 0; color: #666;'>Original Query: <strong>" . htmlspecialchars($topic) . "</strong></p>";
    $page .= "</div>";
    
    // LLM Generated Content
    $page .= "<div style='background-color: #f0f8ff; padding: 15px; border-radius: 5px; margin-bottom: 25px; border: 1px solid #007BFF;'>";
    $page .= "<h3 style='color: #007BFF; margin-top: 0;'>LLM-Generated Summary</h3>";
    
    $page .= "<div style='margin-bottom: 15px;'>";
    $page .= "<strong>Topic:</strong><br>";
    $page .= "<div style='padding: 10px; background: white; border-radius: 4px; margin-top: 5px;'>";
    $page .= nl2br(htmlspecialchars($llmData['topic']));
    $page .= "</div>";
    $page .= "</div>";
    
    $page .= "<div style='margin-bottom: 15px;'>";
    $page .= "<strong>Title:</strong><br>";
    $page .= "<div style='padding: 10px; background: white; border-radius: 4px; margin-top: 5px;'>";
    $page .= htmlspecialchars($llmData['title']);
    $page .= "</div>";
    $page .= "</div>";
    
    $page .= "<div style='margin-bottom: 15px;'>";
    $page .= "<strong>Description:</strong><br>";
    $page .= "<div style='padding: 10px; background: white; border-radius: 4px; margin-top: 5px; min-height: 80px;'>";
    $page .= nl2br(htmlspecialchars($llmData['description']));
    $page .= "</div>";
    $page .= "</div>";
    
    $page .= "<div>";
    $page .= "<strong>Keywords:</strong><br>";
    $page .= "<div style='padding: 10px; background: white; border-radius: 4px; margin-top: 5px;'>";
    $page .= htmlspecialchars($llmData['keywords']);
    $page .= "</div>";
    $page .= "</div>";
    $page .= "</div>";
    
    // Search Results
    $page .= "<div style='margin-bottom: 20px;'>";
    $page .= "<h3 style='background: #4a6fa5; color: white; padding: 8px; border-radius: 4px;'>Web Results (3)</h3>";
    
    if (!empty($webResults)) {
        foreach ($webResults as $web) {
            $page .= "<div style='padding: 10px; margin-bottom: 10px; border-left: 3px solid #4a6fa5; background: #f8f9fa;'>";
            $page .= "<div style='font-weight: bold; color: #1a0dab;'>" . htmlspecialchars($web['title']) . "</div>";
            $page .= "<div style='color: #545454; font-size: 0.9em;'>" . htmlspecialchars($web['description']) . "</div>";
            $page .= "</div>";
        }
    }
    $page .= "</div>";
    
    // Images and Videos in two columns
    $page .= "<div style='display: flex; gap: 20px; margin-bottom: 20px;'>";
    
    // Images
    $page .= "<div style='flex: 1;'>";
    $page .= "<h3 style='background: #e74c3c; color: white; padding: 8px; border-radius: 4px;'>Images (5)</h3>";
    
    if (!empty($images)) {
        $page .= "<div style='display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-top: 10px;'>";
        for ($i = 0; $i < min(5, count($images)); $i++) {
            $img = $images[$i];
            $page .= "<div style='border: 1px solid #ddd; border-radius: 4px; overflow: hidden;'>";
            $page .= "<img src='" . htmlspecialchars($img['thumb_url']) . "' style='width: 100%; height: 100px; object-fit: cover;'>";
            $page .= "<div style='padding: 5px; font-size: 0.8em;'>" . htmlspecialchars(substr($img['title'], 0, 40)) . "</div>";
            $page .= "</div>";
        }
        $page .= "</div>";
    }
    $page .= "</div>";
    
    // Videos
    $page .= "<div style='flex: 1;'>";
    $page .= "<h3 style='background: #27ae60; color: white; padding: 8px; border-radius: 4px;'>Videos (5)</h3>";
    
    if (!empty($videos)) {
        for ($i = 0; $i < min(5, count($videos)); $i++) {
            $video = $videos[$i];
            $page .= "<div style='border: 1px solid #ddd; border-radius: 4px; padding: 8px; margin-bottom: 8px; background: white;'>";
            $page .= "<div style='display: flex; gap: 10px;'>";
            $page .= "<img src='" . htmlspecialchars($video['thumb_url']) . "' style='width: 120px; height: 68px; object-fit: cover;'>";
            $page .= "<div style='flex: 1;'>";
            $page .= "<div style='font-weight: bold; font-size: 0.9em;'>" . htmlspecialchars(substr($video['title'], 0, 60)) . "</div>";
            $page .= "</div>";
            $page .= "</div>";
            $page .= "</div>";
        }
    }
    $page .= "</div>";
    $page .= "</div>";
    
    // Precision Evaluation Section
    $page .= "<div style='border: 2px solid #6c757d; padding: 15px; border-radius: 5px; background: #f8f9fa;'>";
    $page .= "<h3 style='margin-top: 0;'>Precision Evaluation</h3>";
    $page .= "<p><strong>Instructions:</strong> For each result above, mark ‚úì if relevant to the LLM-generated topic, ‚úó if not.</p>";
    
    $page .= "<table style='width: 100%; border-collapse: collapse; margin-top: 10px;'>";
    $page .= "<tr style='background: #e9ecef;'><th>Category</th><th>Relevant (‚úì)</th><th>Total</th><th>Precision</th></tr>";
    $page .= "<tr><td>Web Results</td><td>____ / 3</td><td>3</td><td>P@3 = ____%</td></tr>";
    $page .= "<tr><td>Image Results</td><td>____ / 5</td><td>5</td><td>P@5 = ____%</td></tr>";
    $page .= "<tr><td>Video Results</td><td>____ / 5</td><td>5</td><td>P@5 = ____%</td></tr>";
    $page .= "</table>";
    $page .= "</div>";
    
    $page .= "</div>";
    
    return $page;
}

// ============================================
// HTML OUTPUT
// ============================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LLM Topic Generation - Integrated</title>
    <style>
        @media print {
            @page { margin: 0.5cm; }
            body { font-size: 11pt; }
            .evaluation-page { page-break-after: always; padding: 0.8cm; }
            .no-print { display: none !important; }
        }
        @media screen {
            body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
            .evaluation-page { background: white; padding: 20px; margin-bottom: 20px; box-shadow: 0 0 10px rgba(0,0,0,0.1); max-width: 21cm; margin-left: auto; margin-right: auto; }
            .print-controls { position: fixed; top: 20px; right: 20px; background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.2); z-index: 1000; }
            .progress-info { max-width: 21cm; margin: 0 auto 20px auto; }
        }
        img { max-width: 100%; object-fit: cover; }
        table { border: 1px solid #ddd; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
    </style>
</head>
<body>
    <div class="print-controls no-print">
        <h3 style="margin-top: 0;">Phase 2: LLM Evaluation</h3>
        <p><strong>Task:</strong> Evaluate precision against LLM-generated topics</p>
        <button onclick="window.print()" style="padding: 10px 20px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer; margin-bottom: 10px;">
            Print Evaluation Sheets
        </button>
        <p style="font-size: 12px; color: #666; margin: 5px 0;">
            Format: 3 Web + 5 Images + 5 Videos per page
        </p>
    </div>
    
    <div class="header no-print" style="text-align: center; margin-bottom: 20px; max-width: 21cm; margin-left: auto; margin-right: auto;">
        <h1>Phase 2: LLM-Generated Topic Evaluation</h1>
        <p><strong>Main Query:</strong> <?php echo isset($_GET["query"]) ? htmlspecialchars($_GET["query"]) : "No query provided"; ?></p>
        <div style="background: #d4edda; padding: 10px; border-radius: 4px; margin-top: 10px;">
            <p style="margin: 0; color: #155724;"><strong>Note:</strong> This page generates LLM topics from search results. Each topic will take 30-60 seconds.</p>
        </div>
    </div>
    
    <?php
    if(isset($_GET["query"])) {
        $query = htmlspecialchars($_GET["query"]);
        
        echo "<div class='documents-container'>";
        echo generateLLMTopicsForEvaluation($query);
        echo "</div>";
        
    } else {
        echo "<div style='text-align: center; padding: 50px; max-width: 21cm; margin: 0 auto;'>";
        echo "<h2>No Query Provided</h2>";
        echo "<p>Please provide a query parameter: <code>searchresults3.php?query=your+search+term</code></p>";
        echo "<p>Example: <a href='?query=transformers'>?query=transformers</a></p>";
        echo "</div>";
    }
    ?>
    
    <script>
        // Keep connection alive
        function keepAlive() {
            fetch('?keepalive=1').catch(() => {});
        }
        
        // Send keep-alive every 2 minutes
        setInterval(keepAlive, 120000);
        
        // Scroll progress console to bottom
        window.addEventListener('load', function() {
            var console = document.getElementById('progress-console');
            if (console) {
                console.scrollTop = console.scrollHeight;
            }
        });
    </script>
</body>
</html>