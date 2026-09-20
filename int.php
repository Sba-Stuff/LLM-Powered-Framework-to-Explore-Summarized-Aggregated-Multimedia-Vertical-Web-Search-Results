<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ExSMuV - Grid Search</title>
    
    <!-- External CSS -->
    <link rel="stylesheet" href="css/basic.css">
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/snippetbase.css">
    
    <style>
        /* Reset and Base */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            color: #333;
            line-height: 1.6;
            padding: 20px;
        }
        
        /* Header */
        .header {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #007BFF;
            padding: 0 10px;
        }
        
        .search-box {
            flex: 1;
            display: flex;
            gap: 10px;
        }
        
        .search-input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 16px;
        }
        
        .search-btn {
            padding: 10px 20px;
            background: #007BFF;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            white-space: nowrap;
        }
        
        .search-btn:hover {
            background: #0056cc;
        }
        
        .workspace-select {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: white;
            font-size: 14px;
            min-width: 120px;
        }
        
        /* Main Container */
        .container {
            display: flex;
            gap: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* Left Panel - Grid Results */
        .left-panel {
            flex: 3;
        }
        
        /* Grid Container */
        .grid-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        
        /* Grid Item */
        .grid-item {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            height: 100%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .grid-item:hover {
            border-color: #007BFF;
            box-shadow: 0 4px 12px rgba(0,123,255,0.15);
            transform: translateY(-2px);
        }
        
        /* Thumbnail Container */
        .item-thumbnails {
            padding: 12px;
            background: #f8f9fa;
            border-bottom: 1px solid #f0f0f0;
        }
        
        /* Image Grid */
        .thumb-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
            margin-bottom: 10px;
        }
        
        .thumb-grid div {
            aspect-ratio: 1;
            overflow: hidden;
            border-radius: 4px;
            border: 1px solid #e0e0e0;
            background: white;
        }
        
        .thumb-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.2s ease;
        }
        
        .thumb-grid div:hover .thumb-img {
            transform: scale(1.05);
        }
        
        /* Video Thumbnails */
        .video-thumbs {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding: 4px 0;
            scrollbar-width: thin;
            scrollbar-color: #ccc transparent;
        }
        
        .video-thumbs::-webkit-scrollbar {
            height: 4px;
        }
        
        .video-thumbs::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .video-thumbs::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 2px;
        }
        
        .video-thumb {
            flex: 0 0 auto;
            width: 100px;
            height: 60px;
            position: relative;
            border-radius: 4px;
            overflow: hidden;
            border: 1px solid #e0e0e0;
            background: #000;
        }
        
        .video-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: 0.9;
            transition: opacity 0.2s ease;
        }
        
        .video-thumb:hover img {
            opacity: 1;
        }
        
        .video-play {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 18px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
            background: rgba(0,0,0,0.4);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease;
        }
        
        .video-thumb:hover .video-play {
            background: rgba(0,123,255,0.8);
        }
        
        /* Content Area */
        .item-content {
            padding: 16px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .item-title {
            font-size: 16px;
            font-weight: 500;
            color: #1a0dab;
            margin: 0;
            line-height: 1.3;
        }
        
        .item-title:hover {
            color: #0d47a1;
            text-decoration: underline;
        }
        
        .item-date {
            color: #70757a;
            font-size: 12px;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .item-snippet {
            font-size: 13px;
            color: #4d5156;
            line-height: 1.5;
            margin: 0;
            flex: 1;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 4;
            -webkit-box-orient: vertical;
        }
        
        /* Details Button */
        .details-btn {
            background: #007BFF;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
            align-self: flex-start;
            margin-top: auto;
            min-width: 120px;
        }
        
        .details-btn:hover {
            background: #0056cc;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,123,255,0.3);
        }
        
        /* Right Panel - Details */
        .right-panel {
            flex: 1;
            background: white;
            border-radius: 6px;
            border: 1px solid #ddd;
            min-width: 300px;
            position: sticky;
            top: 20px;
            align-self: flex-start;
            max-height: calc(100vh - 100px);
            overflow-y: auto;
        }
        
        .detail-content {
            padding: 20px;
        }
        
        .detail-title {
            font-size: 18px;
            margin-bottom: 15px;
            color: #333;
            font-weight: 500;
        }
        
        .detail-placeholder {
            color: #666;
            font-size: 14px;
            text-align: center;
            padding: 40px 20px;
        }
        
        /* Loading States */
        .loading {
            grid-column: 1 / -1;
            text-align: center;
            padding: 40px;
            color: #666;
            background: white;
            border-radius: 6px;
            border: 1px solid #ddd;
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .container {
                flex-direction: column;
            }
            
            .right-panel {
                position: static;
                min-width: auto;
                max-height: none;
            }
            
            .grid-container {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .header {
                flex-wrap: wrap;
            }
            
            .search-box {
                order: 3;
                width: 100%;
                margin-top: 10px;
            }
            
            .grid-container {
                grid-template-columns: 1fr;
            }
            
            body {
                padding: 10px;
            }
            
            .grid-item {
                border-radius: 6px;
            }
            
            .item-thumbnails {
                padding: 10px;
            }
            
            .thumb-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 4px;
            }
            
            .video-thumb {
                width: 90px;
                height: 54px;
            }
            
            .item-content {
                padding: 12px;
                gap: 6px;
            }
            
            .item-title {
                font-size: 15px;
            }
            
            .item-snippet {
                font-size: 12px;
                -webkit-line-clamp: 3;
            }
            
            .details-btn {
                padding: 6px 12px;
                font-size: 12px;
                min-width: 110px;
            }
        }
        
        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            .grid-item {
                background: #1e1e1e;
                border-color: #333;
            }
            
            .item-thumbnails {
                background: #2d2d2d;
                border-color: #333;
            }
            
            .item-title {
                color: #8ab4f8;
            }
            
            .item-snippet {
                color: #bdc1c6;
            }
            
            .thumb-grid div {
                border-color: #444;
            }
            
            .video-thumb {
                border-color: #444;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="logo">ExSMuV</div>
        <div class="search-box">
            <input 
                type="text" 
                id="search-input" 
                class="search-input" 
                placeholder="Search here..."
                value="<?php echo isset($_GET['query']) ? htmlspecialchars($_GET['query']) : ''; ?>"
            >
            <button id="search-button" class="search-btn">Search</button>
        </div>
        <select id="workspace" class="workspace-select">
            <option value="Grid" selected>Grid</option>
            <option value="Canvas">Canvas</option>
        </select>
    </div>
    
    <!-- Main Content -->
    <div class="container">
        <!-- Left Panel - Grid Results -->
        <div class="left-panel">
            <div id="nodes-container" class="grid-container">
                <div class="loading">Enter a search query above</div>
            </div>
        </div>
        
        <!-- Right Panel - Details -->
        <div class="right-panel">
            <div id="detail-view" class="detail-content">
                <div class="detail-title">Details Panel</div>
                <div class="detail-placeholder">
                    Click "More Details" on any result to see detailed information here.
                </div>
            </div>
        </div>
    </div>

    <script>
        // Load More Details Function
        function loadMoreDetails(title) {
            const detailView = document.getElementById('detail-view');
            detailView.innerHTML = '<div class="loading">Loading details...</div>';
            
            const xhr = new XMLHttpRequest();
            xhr.open('GET', 'caller/cccallme.php?title=' + encodeURIComponent(title), true);
            xhr.onload = function () {
                if (xhr.status === 200) {
                    detailView.innerHTML = xhr.responseText;
                } else {
                    detailView.innerHTML = '<div class="detail-placeholder">Failed to load details</div>';
                }
            };
            xhr.onerror = function () {
                detailView.innerHTML = '<div class="detail-placeholder">Network error</div>';
            };
            xhr.send();
        }

        // Search Functionality
        document.getElementById("search-button").addEventListener("click", performSearch);
        document.getElementById("search-input").addEventListener("keypress", function(e) {
            if (e.key === "Enter") performSearch();
        });

        function performSearch() {
            const query = document.getElementById("search-input").value.trim();
            const container = document.getElementById("nodes-container");

            if (!query) {
                container.innerHTML = '<div class="loading">Please enter a search query</div>';
                return;
            }

            container.innerHTML = '<div class="loading">Searching...</div>';

            fetch(`searchresults.php?query=${encodeURIComponent(query)}`)
                .then(res => res.text())
                .then(html => {
                    container.innerHTML = "";

                    const tempDiv = document.createElement("div");
                    tempDiv.innerHTML = html;

                    const snippets = tempDiv.querySelectorAll(".snippet");

                    if (snippets.length === 0) {
                        container.innerHTML = '<div class="loading">No results found</div>';
                        return;
                    }

                    snippets.forEach(snippet => {
                        // Create grid item
                        const gridItem = document.createElement("div");
                        gridItem.className = "grid-item";
                        
                        // Get content from snippet
                        const title = snippet.querySelector('h3')?.textContent || 'No title';
                        const description = snippet.querySelector('p')?.textContent || '';
                        const button = snippet.querySelector('button');
                        const thumbnailContainer = snippet.querySelector('.thumbnail-container');
                        
                        // Extract date if present in description
                        let date = '';
                        let descText = description;
                        const dateMatch = description.match(/^(.*?\d{1,2}\s+\w+\s+\d{4})/);
                        if (dateMatch) {
                            date = dateMatch[1];
                            descText = description.replace(date, '').trim();
                        }
                        
                        // Extract button title
                        let buttonTitle = '';
                        if (button) {
                            const onclickMatch = button.getAttribute('onclick')?.match(/'([^']+)'/);
                            buttonTitle = onclickMatch ? onclickMatch[1] : '';
                        }
                        
                        // Build grid item HTML
                        gridItem.innerHTML = `
                            ${thumbnailContainer ? `
                                <div class="item-thumbnails">
                                    ${thumbnailContainer.innerHTML}
                                </div>
                            ` : ''}
                            <div class="item-content">
                                <div class="item-title">${title}</div>
                                ${date ? `<div class="item-date">${date}</div>` : ''}
                                <div class="item-snippet">${descText}</div>
                                ${buttonTitle ? `<button class="details-btn" onclick="loadMoreDetails('${buttonTitle.replace(/'/g, "\\'")}')">More Details</button>` : ''}
                            </div>
                        `;
                        
                        // Process thumbnails for better layout
                        const thumbContainer = gridItem.querySelector('.item-thumbnails');
                        if (thumbContainer) {
                            // Find all images
                            const images = thumbContainer.querySelectorAll('img');
                            const videos = thumbContainer.querySelectorAll('video');
                            
                            // Clear and rebuild
                            thumbContainer.innerHTML = '';
                            
                            if (images.length > 0) {
                                const imgGrid = document.createElement('div');
                                imgGrid.className = 'thumb-grid';
                                images.forEach(img => {
                                    const imgWrapper = document.createElement('div');
                                    const newImg = img.cloneNode();
                                    newImg.className = 'thumb-img';
                                    imgWrapper.appendChild(newImg);
                                    imgGrid.appendChild(imgWrapper);
                                });
                                thumbContainer.appendChild(imgGrid);
                            }
                            
                            if (videos.length > 0) {
                                const videoGrid = document.createElement('div');
                                videoGrid.className = 'video-thumbs';
                                videos.forEach(video => {
                                    const videoWrapper = document.createElement('div');
                                    videoWrapper.className = 'video-thumb';
                                    
                                    const poster = video.getAttribute('poster');
                                    if (poster) {
                                        const videoImg = document.createElement('img');
                                        videoImg.src = poster;
                                        videoImg.alt = 'Video thumbnail';
                                        videoWrapper.appendChild(videoImg);
                                        
                                        const playIcon = document.createElement('div');
                                        playIcon.className = 'video-play';
                                        playIcon.innerHTML = '▶';
                                        videoWrapper.appendChild(playIcon);
                                    }
                                    
                                    videoGrid.appendChild(videoWrapper);
                                });
                                thumbContainer.appendChild(videoGrid);
                            }
                        }
                        
                        container.appendChild(gridItem);
                    });
                })
                .catch(err => {
                    console.error("Search error:", err);
                    container.innerHTML = '<div class="loading">Error loading results</div>';
                });
        }

        // Workspace Selector
        document.getElementById("workspace").addEventListener("change", function() {
            const routes = {
                "Grid": "index.php",
                "Canvas": "index2.php"
            };
            
            const query = document.getElementById("search-input").value;
            const url = routes[this.value];
            
            if (url) {
                if (query) {
                    window.location.href = `${url}?query=${encodeURIComponent(query)}`;
                } else {
                    window.location.href = url;
                }
            }
        });

        // Load search on page load if query exists
        window.addEventListener("DOMContentLoaded", () => {
            const params = new URLSearchParams(window.location.search);
            const query = params.get("query");

            if (query) {
                document.getElementById("search-input").value = query;
                performSearch();
            }
        });
    </script>
</body>
</html>