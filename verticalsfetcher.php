<?php
set_time_limit(7200); // raised: Selenium scraping plus local LLM inference is slow
ini_set('display_errors', 1);
require_once __DIR__ . '/vendor/autoload.php';

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;

function createWebDriver($debug = false)
{
    $options = new ChromeOptions();

    $userAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36";

    $args = [
        '--no-sandbox',
        '--disable-dev-shm-usage',
        '--disable-blink-features=AutomationControlled',
        '--disable-extensions',
        '--disable-popup-blocking',
        '--disable-infobars',
        '--lang=en-US',
        "--user-agent={$userAgent}",
        '--window-size=1280,1024'
    ];

    if (!$debug) {
        $args[] = '--headless=new';
    }

    $options->addArguments($args);

    $capabilities = DesiredCapabilities::chrome();
    $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);

    $serverUrl = 'http://localhost:49400/';
    return RemoteWebDriver::create($serverUrl, $capabilities);
}

function cacheOrRun($prefix, $query, $callback) {
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) mkdir($cacheDir);
    $filename = $cacheDir . '/' . $prefix . '_' . md5($query) . '.json';

    if (file_exists($filename)) {
        return json_decode(file_get_contents($filename), true);
    }

    $data = $callback($query);
    file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT));
    return $data;
}

function BingWebResults($query, $numResults = 10, $debug = false)
{
    return cacheOrRun('web', $query, function($query) use ($numResults, $debug) {
        $driver = createWebDriver($debug);
        $url = "https://www.bing.com/search?q=" . $query; //. "&setlang=en&cc=us";
        $driver->get($url);

        sleep(2);

        try {
            $driver->wait(10)->until(
                WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('li.b_algo h2 > a'))
            );
        } catch (Exception $e) {
            $driver->quit();
            return [];
        }

        $results = [];
        $items = $driver->findElements(WebDriverBy::cssSelector('li.b_algo'));

        foreach ($items as $item) {
            if (count($results) >= $numResults) break;

            try {
                $titleEl = $item->findElement(WebDriverBy::cssSelector('h2 > a'));
                $title = trim($titleEl->getText());
                $link = $titleEl->getAttribute('href');
            } catch (Exception $e) {
                continue;
            }
			$wait = new \Facebook\WebDriver\WebDriverWait($driver, 10); // 10 seconds timeout
            $description = '';
            try {
			    $wait->until(WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('.b_caption p')));
                $descEl = $item->findElement(WebDriverBy::cssSelector('.b_caption p'));
                $description = trim($descEl->getText());
            } catch (Exception $e) {
                $description = '';
            }

            if (!empty($title) && !empty($link) && !empty($description)) {
                $results[] = [
                    'title' => $title,
                    'link' => $link,
                    'description' => $description
                ];
            }
        }

        $driver->quit();
        return $results;
    });
}

function scrapeBingVideoResults($query, $debug = false) {
    return cacheOrRun('videos', $query, function($query) use ($debug) {
        $driver = createWebDriver($debug);
        $url = "https://www.bing.com/videos/search?q=" . $query . "&FORM=HDRSC4&first=1&setlang=en&cc=us";
        $driver->get($url);

        $scrollStep = 500;
        $pause = 800000;
        $maxScroll = 4000;

        for ($y = 0; $y <= $maxScroll; $y += $scrollStep) {
            $driver->executeScript("window.scrollTo(0, $y);");
            usleep($pause);
        }

        $html = $driver->getPageSource();
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $results = [];
        $videoNodes = $xpath->query("//div[contains(@class, 'mmlp')]");

        foreach ($videoNodes as $node) {
            $video = [];
            $mmetaAttr = $xpath->query(".//div[contains(@class, 'mc_vtvc')]", $node)->item(0)?->getAttribute("mmeta");
            $mmeta = $mmetaAttr ? json_decode(html_entity_decode($mmetaAttr), true) : null;

            $video['video_url']   = $mmeta['murl'] ?? '';
            $video['page_url']    = $mmeta['pgurl'] ?? '';
            $video['thumb_url']   = $mmeta['turl'] ?? '';
            $video['video_id']    = $mmeta['mid'] ?? '';

            $titleNode = $xpath->query(".//div[contains(@class,'mc_vtvc_title')]", $node)->item(0);
            $video['title'] = $titleNode ? trim($titleNode->textContent) : '';

            $durationNode = $xpath->query(".//div[contains(@class,'mc_bc_rc')]/div[contains(@class,'items')]", $node)->item(0);
            $video['duration'] = $durationNode ? trim($durationNode->textContent) : '';

            $metaRow1 = $xpath->query(".//div[contains(@class,'mc_vtvc_meta_row')][1]/span", $node);
            $video['views'] = $metaRow1->item(0)?->textContent ?? '';
            $video['upload_date'] = $metaRow1->item(1)?->textContent ?? '';

            $metaRow2 = $xpath->query(".//div[contains(@class,'mc_vtvc_meta_row')][2]/span", $node);
            $video['source']  = $metaRow2->item(0)?->textContent ?? '';
            $video['channel'] = $metaRow2->item(1)?->textContent ?? '';

            if (!empty($video['video_url']) && !empty($video['title']) && !empty($video['thumb_url']) && !empty($video['page_url'])) {
                $uniqueKey = $video['video_id'] ?: $video['video_url'];
                if (!isset($seen[$uniqueKey])) {
                    $seen[$uniqueKey] = true;
                    $results[] = $video;
                }
            }
        }

        $driver->quit();
        return $results;
    });
}

function BingImageResults($query, $debug = false)
{
    return cacheOrRun('images', $query, function($query) use ($debug) {
        $driver = createWebDriver($debug);
        $url = "https://www.bing.com/images/search?q=" . $query . "&form=HDRSC3&first=1&setlang=en&cc=us";
        $driver->get($url);

        $scrollPause = 800000; // 0.8 seconds
        $scrollStep = 400;
        $maxScroll = 5000;

        for ($y = 0; $y < $maxScroll; $y += $scrollStep) {
            $driver->executeScript("window.scrollTo(0, $y);");
            usleep($scrollPause);
        }

        $html = $driver->getPageSource();

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $imageData = [];

        $nodes = $xpath->query("//a[contains(@class,'iusc')]");
        foreach ($nodes as $node) {
            $mAttr = $node->getAttribute("m");
            $meta = json_decode(html_entity_decode($mAttr), true);
            if (!$meta || !isset($meta['murl'])) continue;

            $image = [
                "title"       => $meta['t'] ?? '',
                "image_url"   => $meta['murl'] ?? '',
                "page_url"    => $meta['purl'] ?? '',
                "thumb_url"   => $meta['turl'] ?? '',
                "description" => $meta['desc'] ?? '',
            ];

            $imageData[] = $image;
        }

        $driver->quit();
        return $imageData;
    });
}

function BingRelatedSearches($query, $debug = false)
{
    return cacheOrRun('related', $query, function($query) use ($debug) {
        $driver = createWebDriver($debug);
        $url = "https://www.bing.com/search?q=" . urlencode($query);
        $driver->get($url);

        sleep(5);
        $driver->executeScript("window.scrollTo(0, document.body.scrollHeight);");
        sleep(3);

        $html = $driver->getPageSource();

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $related = [];
        
        // Try multiple possible selectors for the related searches container
        $selectors = [
            "//div[@id='brsv3']//ul[@class='b_vList b_divsec']/li",
            "//div[contains(@class, 'b_rs')]//ul[contains(@class, 'b_vList')]/li",
            "//div[@id='inline_rs']//ul/li[@class='rslist']"
        ];
        
        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes->length > 0) {
                break;
            }
        }

        foreach ($nodes as $li) {
            $a = $li->getElementsByTagName("a")->item(0);
            if (!$a) continue;

            $href = $a->getAttribute("href");
            if (strpos($href, 'http') !== 0) {
                $href = "https://www.bing.com" . $href;
            }

            // Get the suggestion text - look for div with class 'b_suggestionText'
            $textNode = $xpath->query(".//div[contains(@class, 'b_suggestionText')]", $a)->item(0);
            $text = $textNode ? trim(strip_tags($textNode->nodeValue)) : '';

            // If we didn't find text with the above method, try getting all text content from the anchor
            if (!$text) {
                $text = trim(strip_tags($a->nodeValue));
            }

            if ($text && $href) {
                $related[] = [
                    "text" => $text,
                    "link" => $href
                ];
            }
        }

        $driver->quit();
        return $related;
    });
}

function getBingSuggestions($query, $debug = false)
{
    return cacheOrRun('suggestions', $query, function($query) use ($debug) {
        $driver = createWebDriver($debug);
        try {
            $driver->get("https://www.bing.com");

            $searchInput = $driver->findElement(WebDriverBy::id("sb_form_q"));
            $searchInput->click();
            $searchInput->clear();
            $searchInput->sendKeys($query);

            usleep(800000);

            $driver->wait(5)->until(
                WebDriverExpectedCondition::presenceOfElementLocated(
                    WebDriverBy::cssSelector(".sa_as[aria-live='polite'] ul[role='listbox']")
                )
            );

            $suggestions = $driver->findElements(WebDriverBy::cssSelector("ul[role='listbox'] li.sa_sg, li.pp_sTile"));
            $results = [];

            foreach ($suggestions as $item) {
                $text = $item->getAttribute('aria-label') ?: $item->getAttribute('query');
                if (!empty($text)) {
                    $results[] = $text;
                }
            }

            return $results;
        } catch (Exception $e) {
            return ["Error: " . $e->getMessage()];
        } finally {
            $driver->quit();
        }
    });
}
function rankTitlesByCosineSimilarity(string $query, array $titles): array {
    // Combine query and titles into a single array
    $documents = array_merge([$query], $titles);
    $tokenized = [];

    // Tokenize and lowercase each document
    foreach ($documents as $doc) {
        $tokens = preg_split('/\W+/', strtolower($doc), -1, PREG_SPLIT_NO_EMPTY);
        $tokenized[] = $tokens;
    }

    // Build vocabulary
    $vocab = [];
    foreach ($tokenized as $tokens) {
        foreach ($tokens as $token) {
            $vocab[$token] = true;
        }
    }
    $vocab = array_keys($vocab);
    $vocabIndex = array_flip($vocab);
    $numTerms = count($vocab);
    $numDocs = count($tokenized);

    // Term Frequency (TF) vectors
    $tfVectors = [];
    foreach ($tokenized as $tokens) {
        $vec = array_fill(0, $numTerms, 0);
        foreach ($tokens as $token) {
            if (isset($vocabIndex[$token])) {
                $vec[$vocabIndex[$token]] += 1;
            }
        }
        $tfVectors[] = $vec;
    }

    // Document Frequency (DF)
    $df = array_fill(0, $numTerms, 0);
    foreach ($tfVectors as $vec) {
        foreach ($vec as $i => $count) {
            if ($count > 0) {
                $df[$i] += 1;
            }
        }
    }

    // Compute IDF (with smoothing)
    $idf = [];
    foreach ($df as $dfi) {
        $idf[] = log(($numDocs + 1) / ($dfi + 1)) + 1;
    }

    // TF-IDF Vectors
    $tfidfVectors = [];
    foreach ($tfVectors as $vec) {
        $tfidf = [];
        foreach ($vec as $i => $tf) {
            $tfidf[] = $tf * $idf[$i];
        }
        $tfidfVectors[] = $tfidf;
    }

    // Cosine similarity between query (0) and each title
    $queryVec = $tfidfVectors[0];
    $ranked = [];

    for ($i = 1; $i < count($tfidfVectors); $i++) {
        $titleVec = $tfidfVectors[$i];
        $dot = 0;
        $queryNorm = 0;
        $titleNorm = 0;

        for ($j = 0; $j < $numTerms; $j++) {
            $dot += $queryVec[$j] * $titleVec[$j];
            $queryNorm += $queryVec[$j] ** 2;
            $titleNorm += $titleVec[$j] ** 2;
        }

        $denom = sqrt($queryNorm) * sqrt($titleNorm);
        $cosine = ($denom > 0) ? $dot / $denom : 0;

        $ranked[] = [
            'title' => $titles[$i - 1],
            'score' => round($cosine, 4),
        ];
    }

    // Sort by descending score
    usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);

    return $ranked;
}
?>