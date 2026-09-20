# LLM-ExSMuV — LLM Powered Exploration Software for Summarized Multimedia Vertical Search Results

<img width="1668" height="206" alt="image" src="https://github.com/user-attachments/assets/af8c837a-64d8-4d63-8410-0dba8b764e0f" />


[![Build Status](https://img.shields.io/badge/build-passing-brightgreen)](https://github.com/Sba-Stuff/ExSMuV)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Last Updated](https://img.shields.io/badge/last%20updated-September%202026-orange)](https://github.com/Sba-Stuff/ExSMuV)

> ExSMuV summarizes aggregated multimedia vertical web search results into topic-coherent
> multimedia documents and presents them in a non-linear interface, so that users can explore
> web, image and video results without switching between vertical tabs.

---

## Table of Contents

- [About](#about)
- [Two Versions](#two-versions)
- [Requirements](#requirements)
- [Folder Structure](#folder-structure)
- [Setup Instructions](#setup-instructions)
- [Setting Up the Local LLM](#setting-up-the-local-llm)
- [Configuration](#configuration)
- [How It Works](#how-it-works)
- [Troubleshooting](#troubleshooting)
- [Publications](#publications)
- [Citation](#citation)
- [License](#license)

---

## About

Modern search engines return heterogeneous results across separate verticals such as web pages,
images and videos. Each vertical is ranked independently and shown in its own tab. Users who are
exploring a topic rather than looking up a single fact must therefore switch between tabs, scroll
through long ranked lists and mentally reassemble fragmented information. Generative AI answer
boxes compress the top of the page but leave that fragmentation in place.

ExSMuV takes a different route. It retrieves results from three verticals, mines the topics that
emerge from their metadata, groups semantically related results under each topic and composes a
single multimedia document per topic. The user explores topics rather than tabs.

In the reported user study the framework reduced clicks from 30.8 to 21.5 (p < 0.01), scrolls from
218.4 to 74.6 (p < 0.001) and vertical switches from 3.7 to 0, while system usability rose from 77%
to 88% (p < 0.05).

---

## Two Versions

This repository contains the LLM-powered version described in the IJIST 2026 paper.

| | v1 — TopicMiner | v2 — LLM-Powered (current) |
|---|---|---|
| Topic extraction | Frequency-based `TopicMiner` | Hybrid: `TopicMiner` + LLM keyword extraction |
| Title and description | Word-frequency heuristic, per result | Extractive LLM summarization over aggregated metadata |
| Local LLM required | No | Yes (LM Studio) |
| Paper | SoftwareX 2026 | IJIST 2026 |

v2 degrades gracefully. If LM Studio is not running, ExSMuV falls back to the v1 frequency-based
behaviour and the interface still works. See [Troubleshooting](#troubleshooting).

---

## Requirements

- 64-bit **Windows** operating system
- **Chromedriver**
- **Standalone Chrome** (Selenium automation version)
- **Server software** (UniServer, WAMP, XAMPP or similar) with the `curl` and `zlib`
  extensions enabled. `mbstring` is recommended.
- **LM Studio** with the `lfm2-1.2b` model, for the LLM-powered features

---

## Folder Structure

Create the following folders anywhere on your PC:

```
ExSMuV/
├── chromedriver/
├── chrome/
└── server/
```

---

## Setup Instructions

1. **Download Chromedriver**
   Extract it into the `chromedriver/` folder.

2. **Download Chrome (standalone)**
   Extract it into the `chrome/` folder.
   > If Chrome is already running, exit it from the system tray to avoid conflicts.

3. **Download a local server**
   Use UniServer, WAMP, XAMPP or similar and extract it into the `server/` folder.
   > This guide uses UniServer as the example.

4. **Enable the `curl` extension**
   Turn `curl` on in your server's PHP configuration. Enable `mbstring` as well if it is
   available. ExSMuV includes fallback shims for `mbstring`, but the real extension handles
   non-Latin text correctly.

5. **Start Chromedriver**
   - Open the `chromedriver/` folder.
   - Type `cmd` in the address bar and press Enter.
   - Run:

     ```bash
     chromedriver.exe --port=49400
     ```

   - When it reports "Running", minimize the window. Do not close it.

6. **Run Chrome**
   Open `chrome/Chrome.exe`.

7. **Download the ExSMuV code**
   Clone or download this repository and extract it into your server's `htdocs/` or `www/`
   directory.

8. **Start LM Studio**
   See [Setting Up the Local LLM](#setting-up-the-local-llm) below. Skip this step if you only
   want the v1 frequency-based behaviour.

9. **Launch the server**
   Start your server, for example `UniServerZ.exe`.

10. **Open the browser**
    Navigate to:

    ```
    http://localhost
    ```

    You should see the ExSMuV interface.

11. **Try the sample queries**
    `Chemistry` and `Harry Potter` are cached for demonstration.

### When you are done

- Close the Chrome browser
- Close the command prompt running Chromedriver
- Stop LM Studio
- Shut down the server

---

## Setting Up the Local LLM

ExSMuV talks to a local LM Studio server over the OpenAI-compatible chat completions API. No
data leaves your machine and no API key or paid service is required.

1. Install **LM Studio**.
2. Download the model **`lfm2-1.2b`** by Liquid, quantization **Q8_0** (about 1.25 GB).
3. Load the model and set the **context length to 10000**.
4. Start the local server. The default address is:

   ```
   http://127.0.0.1:1234
   ```

5. Confirm the server is reachable before running a search.

Temperature and max tokens are sent by ExSMuV on every request, so they do not need to be set
in the LM Studio interface.

### Model parameters

| Attribute | Value |
|---|---|
| Model name | `lfm2-1.2b` |
| Author | Liquid |
| Quantization | Q8_0 |
| Size | 1.25 GB |
| Context length | 10,000 |
| Temperature | 0.15 |
| Max tokens | 2,000 |

The model is deliberately small. The framework constrains it to an extractive role rather than
open-ended generation, so a large model is unnecessary and a local one keeps latency and cost
predictable.

---

## Configuration

All tunable constants sit at the top of `OpenLLM.php`.

```php
EXSMUV_LLM_URL          'http://127.0.0.1:1234/v1/chat/completions'
EXSMUV_LLM_MODEL        'liquid/lfm2-1.2b'
EXSMUV_LLM_TEMPERATURE  0.15
EXSMUV_LLM_MAX_TOKENS   2000
EXSMUV_LLM_CONTEXT      10000

EXSMUV_TIMEOUT          7200    // seconds; local inference can be slow
EXSMUV_TOPIC_LIMIT      5       // k, maximum multimedia documents per query
EXSMUV_KEYWORD_LIMIT    10      // maximum keywords per extraction
EXSMUV_SIM_THRESHOLD    0.65    // theta, topic relevance threshold
EXSMUV_EXTRACTIVE_MIN   0.85    // minimum token overlap with the source
EXSMUV_TOPIC_DEDUPE     0.80    // Jaccard threshold for near-duplicate topics

EXSMUV_CARD_IMAGES      3       // ranked images per multimedia document
EXSMUV_CARD_VIDEOS      5       // ranked videos per multimedia document
EXSMUV_CARD_WEB         5       // ranked web references per multimedia document
EXSMUV_QUERY_WEIGHT     1       // weight of the query vs the card title when
                                // ranking the items inside a card

EXSMUV_LLM_CACHE        true    // cache LLM responses on disk
EXSMUV_LLM_DEBUG        false   // true writes logs/ and shows provenance tags
```

`searchresults.php` has one further switch:

```php
EXSMUV_SUMMARIZE_MODE   'aggregate'   // one document from all results (default)
                        'per_result'  // one document per result (legacy layout)
```

---

## How It Works

```
query
  │
  ├─► Seed retrieval ─────────► web results for the query
  │
  ├─► Topic generation
  │     ├─ related searches and query suggestions
  │     ├─ TopicMiner              lexical, frequency-based
  │     └─ LLM keyword extraction  semantic, extractive
  │
  ├─► Deduplication              exact, substring and token-set near-duplicates
  │                              removed BEFORE ranking
  │
  ├─► Topic ranking              TF-IDF cosine similarity against the query
  │
  ├─► Topic selection            top k above theta, bounded working set
  │
  ├─► Per topic:
  │     ├─ retrieve web, images and videos
  │     ├─ re-rank each vertical against the topic
  │     ├─ keep top 5 web, top 3 images, top 5 videos
  │     └─ LLM extractive summarization over the ranked web metadata
  │
  └─► Search User Interface      non-linear grid of multimedia documents
```

### Anatomy of a multimedia document

Each card is one topic, assembled from all three verticals:

| Element | Count | Source |
|---|---|---|
| Title | 1 | LLM extractive summarization over the topic's web metadata |
| Description | 1 | same |
| Image thumbnails | 3 | Image vertical, re-ranked against the topic |
| Video posters | 5 | Video vertical, re-ranked against the topic |
| Web references | 5 | Web vertical, re-ranked against the topic |

Because everything on a card belongs to one topic, the user never switches
verticals to see the images or videos that relate to what they are reading.

### Deduplication

The four topic sources overlap heavily. Related searches, suggestions,
TopicMiner and the LLM routinely return the same idea in different wording:
`Harry Potter movies`, `harry potter movies`, `the harry potter movies`,
`harry potter movie` and `Harry Potter` are five candidates for one topic.

Duplicates are removed before ranking, using three tests in increasing cost:

1. identical normalised form
2. one normalised form contains the other
3. token-set Jaccard similarity at or above `EXSMUV_TOPIC_DEDUPE`

Normalisation lowercases, strips punctuation and removes function words from
anywhere in the phrase, so `cast of harry potter` and `harry potter cast`
collapse to one topic. The more specific wording survives, and topics equal to
the query itself are dropped since the query already has its own document.

### Result ranking

Cosine similarity is applied at two distinct points in the pipeline.

**1. Between cards.** Candidate topics are ranked against the user's original
query. This decides which topics become multimedia documents.

```
score(topic) = cos( tfidf(query), tfidf(topic) )
```

**2. Within a card.** The web, image and video results retrieved for a topic
are ranked against the user's original query **combined with that card's
title**. This decides which items are placed on the card.

```
context      = query + card title
score(item)  = cos( tfidf(context), tfidf(item) )
```

Both signals are used on purpose. Ranking on the card title alone lets a card
drift away from what the user actually asked for, because a mined topic is only
a fragment of the information need. Ranking on the query alone would make every
card show near-identical results. Combining them keeps each card on its own
topic while staying anchored to the query.

Retrieval still uses the topic text on its own, since that is what the search
engine needs in order to return results about the topic. Only the ranking uses
the combined context. When a card's topic is the query itself, the context is
just the query and is not duplicated.

`EXSMUV_QUERY_WEIGHT` controls the balance. `1` gives the query and the card
title equal weight, `2` counts the query twice, and `0` ranks on the card title
alone. Raising it makes cards more consistent with the query but more similar to
each other.

Ranking preserves the whole record, so thumbnails and URLs survive. Each item
carries a `_score`.

### Key components

| File | Role |
|---|---|
| `index.php` | Search entry point |
| `verticalsfetcher.php` | Retrieves web, image and video results; caching |
| `subtopicminer.php` | `TopicMiner`, lexical topic mining |
| `OpenLLM.php` | LLM integration, extractive validation, topic selection |
| `searchresults.php` | Composes and renders multimedia documents |
| `caller/cccallme.php` | Details panel for a selected document |

### Extractive guarantee

Both prompts instruct the model to use only wording that already appears in the retrieved
metadata. Because a prompt alone cannot enforce this, ExSMuV verifies it. Every generated title
and description is scored for token overlap against its source metadata. Output falling below
`EXSMUV_EXTRACTIVE_MIN` is discarded and the frequency-based method is used instead. Extracted
keywords that do not literally occur in the metadata are dropped.

This keeps everything displayed traceable to a retrieved source and prevents hallucinated
content from reaching the interface.

### Caching

Search results and LLM responses are cached under `cache/`. This keeps repeated queries fast and
makes evaluation reproducible, since the same query yields the same summaries across runs.
Fallback results are deliberately not cached, so a temporary LM Studio outage does not leave
frequency-based titles permanently in place.

To clear the cache, delete the contents of `cache/`.

---

## Troubleshooting

**The page loads but titles look like keyword lists**
LM Studio is probably not reachable, so ExSMuV has fallen back to the frequency-based method.
Check that the server is running on port 1234 and that the model is loaded. Set
`EXSMUV_LLM_DEBUG` to `true` to see a provenance tag under each document showing whether it came
from `llm` or `fallback`.

**Searches take a long time**
The first LLM request after loading a model is the slowest. Later requests are faster and cached
queries are close to instant. `EXSMUV_TIMEOUT` is set to 7200 seconds so that slow local
inference does not abort the request. Reducing `EXSMUV_TOPIC_LIMIT` also shortens each search.

**Most documents show `fallback title_not_extractive_*`**
The model is adding words that are not in the source metadata. Lower `EXSMUV_EXTRACTIVE_MIN`
from `0.85` to about `0.70`, or raise the context length in LM Studio so that less metadata is
truncated.

**Nothing renders at all**
Re-run from step 5. Chromedriver must be running before the server, and Chrome must not already
be open in the system tray.

**Fatal error about `mb_strlen` or `curl_init`**
The `mbstring` or `curl` extension is disabled in your PHP configuration. Enable it and restart
the server.

---

## Publications

**LLM-Powered Framework to Explore Summarized Aggregated Multimedia Vertical Web Search Results**
Muhammad Wajeeh Uz Zaman, Umer Rashid, Abdur Rehman Khan
*International Journal of Innovations in Science & Technology*, Special Issue, pp. 1–11, April 2026.

**ExSMuV: Exploration software for summarized multimedia vertical search results**
Muhammad Wajeeh Uz Zaman, Umer Rashid, Abdur Rehman Khan
*SoftwareX*, 2026.

---

## Citation(s)

```bibtex
@article{zaman2026llm,
  title={LLM-Powered Framework to Explore Summarized Aggregated Multimedia Vertical Web Search Results},
  author={Zaman, Muhammad Wajeeh Uz and Rashid, Umer and Khan, Abdur Rehman},
  journal={International Journal of Innovations in Science \& Technology},
  volume={8},
  number={3},
  pages={01--11},
  year={2026},
  publisher={50sea}
}
```

```bibtex
@article{zaman2026exsmuv,
  title={ExSMuV:[Ex] ploration software for [S] ummarized [Mu] ltimedia [V] ertical search results},
  author={Zaman, Muhammad Wajeeh Uz and Rashid, Umer and Abbas, Qaisar and Khan, Abdur Rehman},
  journal={SoftwareX},
  volume={33},
  pages={102501},
  year={2026},
  publisher={Elsevier}
}
```

---

## License
MIT
