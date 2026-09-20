<?php
// expert_topic_creation.php
// Complete version with square image thumbnails in grid layout

include('debugging.php');

function createExpertTopicPages($query)
{
    $encodedQuery = $query;
    include_once("verticalsfetcher.php");
    include_once("subtopicminer.php");
    
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
    
    // Limit to 31 topics for printing
    $topicsToUse = array_slice($rankedTopics, 0, 30);
    $output = "";
    
    foreach ($topicsToUse as $index => $topicData) {
        $topic = $topicData['title'];
        if (empty($topic)) {
            continue;
        }
        
        set_time_limit(3000);
        ini_set('display_errors', 0);
        
        $output .= createCompactExpertPage($topic, $index + 1);
    }
    
    echo $output;   
}

function createCompactExpertPage($topic, $pageNumber)
{
    include_once("verticalsfetcher.php");
    
    // Fetch minimal results - exactly what we need
    $webResults = BingWebResults($topic, 3, false);
    $videos = scrapeBingVideoResults($topic);
    $images = BingImageResults($topic);
    
    $page = "<div class='expert-page' style='page-break-after: always; padding: 15px; margin: 0; font-family: Arial, sans-serif; font-size: 11pt;'>";
    
    // Minimal header - just page number and topic
    $page .= "<div style='border-bottom: 2px solid #333; padding-bottom: 5px; margin-bottom: 15px;'>";
    $page .= "<div style='float: right; color: #666; font-size: 10pt;'>Page $pageNumber/30</div>";
    $page .= "<h2 style='margin: 0; color: #333; font-size: 16pt;'>Expert Topic Creation</h2>";
    $page .= "</div>";
    
    // Simple instructions
    $page .= "<div style='background-color: #f5f5f5; padding: 8px; margin-bottom: 15px; border-left: 3px solid #007BFF; font-size: 10pt;'>";
    $page .= "<strong>Instructions:</strong> Review the search results below. Write a <strong>concise title</strong>, <strong>description</strong>, and <strong>3-5 keywords</strong> that summarize these results.";
    $page .= "</div>";
    
    // Expert Response Area (compact)
    $page .= "<div style='border: 1px solid #ccc; padding: 10px; margin-bottom: 20px; background-color: #f9f9f9;'>";
    $page .= "<h3 style='margin-top: 0; color: #007BFF; font-size: 12pt;'>Your Summary:</h3>";
    
    $page .= "<table style='width: 100%; border-collapse: collapse;'>";
    $page .= "<tr>";
    $page .= "<td style='width: 20%; padding: 5px; vertical-align: top;'><strong>Topic Title:</strong></td>";
    $page .= "<td style='padding: 5px; border-bottom: 1px dashed #999; height: 25px;'>_______________________________________________________</td>";
    $page .= "</tr>";
    $page .= "<tr>";
    $page .= "<td style='padding: 5px; vertical-align: top;'><strong>Description:</strong></td>";
    $page .= "<td style='padding: 5px;'>";
    $page .= "<div style='border: 1px solid #eee; padding: 8px; min-height: 80px; margin: 5px 0; background-color: white;'>";
    $page .= "<em>(Write 2-3 sentences summarizing the common theme)</em><br><br>";
    $page .= "________________________________________________________________<br>";
    $page .= "________________________________________________________________<br>";
    $page .= "________________________________________________________________<br>";
    $page .= "________________________________________________________________";
    $page .= "</div>";
    $page .= "</td>";
    $page .= "</tr>";
    $page .= "<tr>";
    $page .= "<td style='padding: 5px; vertical-align: top;'><strong>Keywords:</strong></td>";
    $page .= "<td style='padding: 5px;'>";
    $page .= "1. ________________ &nbsp; 2. ________________ &nbsp; 3. ________________<br>";
    $page .= "4. ________________ &nbsp; 5. ________________";
    $page .= "</td>";
    $page .= "</tr>";
    $page .= "</table>";
    $page .= "</div>";
    
    // COMPACT WEB RESULTS (3 results)
    $page .= "<div style='margin-bottom: 15px;'>";
    $page .= "<div style='background-color: #4a6fa5; color: white; padding: 4px 8px; font-weight: bold; font-size: 10pt; margin-bottom: 5px;'>Web Results (3)</div>";
    
    if (!empty($webResults)) {
        for ($j = 0; $j < min(3, count($webResults)); $j++) {
            $web = $webResults[$j];
            $page .= "<div style='margin-bottom: 8px; padding: 6px; border-left: 2px solid #4a6fa5; background-color: #f0f5ff;'>";
            $page .= "<div style='font-weight: bold; font-size: 10pt; color: #1a0dab;'>" . htmlspecialchars($web['title']) . "</div>";
            $page .= "<div style='font-size: 9pt; color: #545454; line-height: 1.3;'>" . htmlspecialchars($web['description']) . "</div>";
            $page .= "<div style='font-size: 8pt; color: #006621; margin-top: 2px;'>" . htmlspecialchars($web['link']) . "</div>";
            $page .= "</div>";
        }
    } else {
        $page .= "<div style='color: #666; font-style: italic; font-size: 9pt; padding: 5px;'>No web results available</div>";
    }
    
    $page .= "</div>";
    
    // Two-column layout for images and videos
    $page .= "<div style='display: flex; gap: 10px;'>";
    
    // LEFT COLUMN: IMAGES (5 results) - SQUARE THUMBNAILS GRID
    $page .= "<div style='flex: 1;'>";
    $page .= "<div style='background-color: #e74c3c; color: white; padding: 4px 8px; font-weight: bold; font-size: 10pt; margin-bottom: 5px;'>Images (5)</div>";
    
    if (!empty($images)) {
        // Create a 2x3 grid (2 columns, 3 rows for 5 images)
        $page .= "<div style='display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px;'>";
        
        for ($j = 0; $j < min(5, count($images)); $j++) {
            $img = $images[$j];
            $page .= "<div style='border: 1px solid #ddd; border-radius: 4px; background-color: white; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);'>";
            
            // Square thumbnail container (1:1 aspect ratio)
            $page .= "<div style='width: 100%; aspect-ratio: 1/1; position: relative; background-color: #f5f5f5; overflow: hidden;'>";
            $page .= "<img src='" . htmlspecialchars($img['thumb_url']) . "' alt='Image' style='width: 100%; height: 100%; object-fit: cover; display: block;'>";
            $page .= "</div>";
            
            // Image metadata below thumbnail
            $page .= "<div style='padding: 5px;'>";
            $page .= "<div style='font-weight: bold; font-size: 8pt; line-height: 1.2; height: 2.4em; overflow: hidden; margin-bottom: 2px;'>" . 
                     htmlspecialchars(substr($img['title'], 0, 50)) . (strlen($img['title']) > 50 ? "..." : "") . "</div>";
            if (!empty($img['description'])) {
                $page .= "<div style='font-size: 7pt; color: #666; line-height: 1.2; height: 2.1em; overflow: hidden;'>" . 
                         htmlspecialchars(substr($img['description'], 0, 60)) . (strlen($img['description']) > 60 ? "..." : "") . "</div>";
            }
            $page .= "</div>";
            $page .= "</div>";
        }
        
        $page .= "</div>"; // Close grid
        
        // Add note about missing images if less than 5
        if (count($images) < 5) {
            $page .= "<div style='color: #999; font-size: 8pt; font-style: italic; margin-top: 5px;'>";
            $page .= "Note: Only " . count($images) . " image results available";
            $page .= "</div>";
        }
    } else {
        $page .= "<div style='color: #666; font-style: italic; font-size: 9pt; padding: 10px; text-align: center; background-color: #f9f9f9; border-radius: 4px;'>";
        $page .= "No image results available";
        $page .= "</div>";
    }
    
    $page .= "</div>"; // Close left column
    
    // RIGHT COLUMN: VIDEOS (5 results)
    $page .= "<div style='flex: 1;'>";
    $page .= "<div style='background-color: #27ae60; color: white; padding: 4px 8px; font-weight: bold; font-size: 10pt; margin-bottom: 5px;'>Videos (5)</div>";
    
    if (!empty($videos)) {
        for ($j = 0; $j < min(5, count($videos)); $j++) {
            $video = $videos[$j];
            $page .= "<div style='margin-bottom: 8px; border: 1px solid #ddd; border-radius: 4px; background-color: white; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);'>";
            $page .= "<div style='display: flex;'>";
            
            // Video thumbnail (16:9 aspect ratio)
            $page .= "<div style='flex-shrink: 0; width: 100px;'>";
            $page .= "<div style='width: 100px; height: 56px; position: relative; background-color: #000; overflow: hidden;'>";
            $page .= "<img src='" . htmlspecialchars($video['thumb_url']) . "' alt='Video thumbnail' style='width: 100%; height: 100%; object-fit: cover;'>";
            // Play button overlay
            $page .= "<div style='position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-size: 18px; opacity: 0.8; text-shadow: 0 0 3px rgba(0,0,0,0.5);'>▶</div>";
            $page .= "</div>";
            $page .= "</div>";
            
            $page .= "<div style='flex: 1; padding: 6px;'>";
            $page .= "<div style='font-weight: bold; font-size: 9pt; color: #1a0dab; line-height: 1.2; margin-bottom: 3px; height: 2.4em; overflow: hidden;'>" . 
                     htmlspecialchars(substr($video['title'], 0, 60)) . (strlen($video['title']) > 60 ? "..." : "") . "</div>";
            if (!empty($video['description'])) {
                $page .= "<div style='font-size: 8pt; color: #666; line-height: 1.2; height: 3.2em; overflow: hidden;'>" . 
                         htmlspecialchars(substr($video['description'], 0, 100)) . (strlen($video['description']) > 100 ? "..." : "") . "</div>";
            }
            $page .= "</div>";
            $page .= "</div>";
            $page .= "</div>";
        }
        
        // Add note about missing videos if less than 5
        if (count($videos) < 5) {
            $page .= "<div style='color: #999; font-size: 8pt; font-style: italic; margin-top: 5px;'>";
            $page .= "Note: Only " . count($videos) . " video results available";
            $page .= "</div>";
        }
    } else {
        $page .= "<div style='color: #666; font-style: italic; font-size: 9pt; padding: 10px; text-align: center; background-color: #f9f9f9; border-radius: 4px;'>";
        $page .= "No video results available";
        $page .= "</div>";
    }
    
    $page .= "</div>"; // Close right column
    $page .= "</div>"; // End two-column layout
    
    // Document ID (small and unobtrusive)
    $page .= "<div style='margin-top: 15px; padding-top: 5px; border-top: 1px solid #eee; text-align: center; color: #999; font-size: 8pt;'>";
    $page .= "Document ID: " . substr(md5($topic . $pageNumber), 0, 6);
    $page .= "</div>";
    
    $page .= "</div>"; // Close expert-page
    
    return $page;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expert Topic Creation - Compact Format</title>
    <style>
        /* PRINT OPTIMIZATION - Critical for reducing pages */
        @media print {
            @page {
                margin: 0.5cm;
                size: A4 portrait;
            }
            
            body {
                margin: 0;
                padding: 0;
                font-family: 'Arial', sans-serif;
                font-size: 11pt !important;
                line-height: 1.2;
                color: #000;
                -webkit-print-color-adjust: exact;
            }
            
            .expert-page {
                page-break-after: always;
                page-break-inside: avoid;
                margin: 0;
                padding: 0.8cm !important;
                height: 27.7cm; /* A4 height minus margins */
                box-sizing: border-box;
                border: none !important;
            }
            
            .no-print {
                display: none !important;
            }
            
            /* Optimize images for print */
            img {
                max-width: 100%;
                max-height: 100px;
                object-fit: cover;
            }
            
            /* Ensure grid layout works in print */
            .image-grid {
                display: grid !important;
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 6px !important;
            }
            
            /* Remove backgrounds if needed for ink saving */
            @media print and (color: 0) {
                .expert-page {
                    background: white !important;
                }
                
                [style*="background-color"] {
                    background-color: transparent !important;
                    border-color: #ccc !important;
                }
            }
        }
        
        /* SCREEN VIEW */
        @media screen {
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background-color: #f5f5f5;
                margin: 20px;
                font-size: 14px;
            }
            
            .expert-page {
                background-color: white;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
                margin: 0 auto 20px auto;
                max-width: 21cm; /* A4 width */
                min-height: 29.7cm; /* A4 height */
                border: 1px solid #ccc;
            }
            
            .print-controls {
                position: fixed;
                top: 20px;
                right: 20px;
                background: white;
                padding: 15px;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.2);
                z-index: 1000;
            }
        }
        
        /* COMMON OPTIMIZATIONS */
        .expert-page {
            box-sizing: border-box;
        }
        
        /* Reduce whitespace */
        h1, h2, h3, h4, h5, h6 {
            margin-top: 0;
            margin-bottom: 0.3em;
        }
        
        p {
            margin-top: 0;
            margin-bottom: 0.5em;
        }
        
        /* Compact table styling */
        table {
            border-spacing: 0;
            border-collapse: collapse;
        }
        
        td {
            padding: 3px 5px;
        }
        
        /* Ensure consistent thumbnail sizing */
        .thumbnail-square {
            aspect-ratio: 1/1;
            object-fit: cover;
        }
        
        .thumbnail-video {
            aspect-ratio: 16/9;
            object-fit: cover;
        }
    </style>
</head>
<body>
    <div class="print-controls no-print">
        <h3 style="margin-top: 0;">Print Instructions</h3>
        <p><strong>Important:</strong> Before printing:</p>
        <ol style="margin-top: 5px; margin-bottom: 10px; padding-left: 20px;">
            <li>Set browser zoom to <strong>85%</strong> (Ctrl + -)</li>
            <li>Use <strong>A4</strong> paper size</li>
            <li>Set margins to <strong>Minimum</strong> or "None"</li>
            <li>Disable headers/footers in print settings</li>
        </ol>
        <button onclick="optimizeForPrint()" style="padding: 8px 15px; background-color: #007BFF; color: white; border: none; border-radius: 4px; cursor: pointer; margin-right: 10px;">
            Optimize View
        </button>
        <button onclick="window.print()" style="padding: 8px 15px; background-color: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer;">
            Print Pages
        </button>
        <p style="margin-top: 10px; font-size: 11px; color: #666;">
            Format: 3 Web + 5 Images (square) + 5 Videos per page
        </p>
    </div>
    
    <div class="header no-print" style="text-align: center; margin-bottom: 20px; max-width: 21cm; margin-left: auto; margin-right: auto;">
        <h1>Expert Topic Creation Task</h1>
        <p><strong>Main Query:</strong> <?php echo isset($_GET["query"]) ? htmlspecialchars($_GET["query"]) : "No query provided"; ?></p>
        <p style="color: #666; font-size: 13px;">Each page shows 3 web, 5 image (square thumbnails), and 5 video results. Please write a summary title, description, and keywords.</p>
    </div>
    
    <?php
    if(isset($_GET["query"])) {
        $query = htmlspecialchars($_GET["query"]);
        
        echo "<div class='documents-container'>";
        echo createExpertTopicPages($query);
        echo "</div>";
    } else {
        echo "<div style='text-align: center; padding: 50px;'>";
        echo "<h2>No Query Provided</h2>";
        echo "<p>Please provide a query parameter: <code>expert_topic_creation.php?query=your+search+term</code></p>";
        echo "</div>";
    }
    ?>
    
    <script>
        // Function to optimize view for printing
        function optimizeForPrint() {
            // Reduce font size slightly
            document.body.style.fontSize = '11pt';
            
            // Reduce all padding/margins
            const allElements = document.querySelectorAll('*');
            allElements.forEach(el => {
                const style = window.getComputedStyle(el);
                if (parseFloat(style.marginTop) > 10) {
                    el.style.marginTop = '5px';
                }
                if (parseFloat(style.marginBottom) > 10) {
                    el.style.marginBottom = '5px';
                }
                if (parseFloat(style.padding) > 15) {
                    el.style.padding = '8px';
                }
            });
            
            // Zoom out
            document.body.style.zoom = '85%';
            
            alert('View optimized for printing. Ready for Ctrl+P.');
        }
        
        // Count pages on load
        document.addEventListener('DOMContentLoaded', function() {
            const pageCount = document.querySelectorAll('.expert-page').length;
            console.log(`Total pages: ${pageCount}`);
            
            if (pageCount !== 30) {
                console.warn(`Expected 30 pages, found ${pageCount}. Check data availability.`);
            }
            
            // Update page counter in print controls
            const pageCounter = document.querySelector('.print-controls p:last-child');
            if (pageCounter && pageCount > 0) {
                pageCounter.innerHTML = `Format: 3 Web + 5 Images (square) + 5 Videos per page<br>Total: ${pageCount} pages`;
            }
        });
        
        // Print optimization
        window.addEventListener('beforeprint', function() {
            // Further reduce font sizes for print
            document.body.style.fontSize = '10pt';
            document.querySelectorAll('.expert-page').forEach(page => {
                page.style.padding = '0.6cm';
            });
            
            // Ensure all images have object-fit: cover
            document.querySelectorAll('img').forEach(img => {
                img.style.objectFit = 'cover';
            });
        });
        
        // After print, restore view
        window.addEventListener('afterprint', function() {
            document.body.style.fontSize = '';
            document.body.style.zoom = '';
        });
    </script>
</body>
</html>