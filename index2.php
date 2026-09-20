<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ExSMuV - Canvas</title>
  <link rel="stylesheet" href="css/canvas.css" />
  <!--<link rel="stylesheet" href="css/resultsnippet.css">-->
  <link rel="stylesheet" href="css/basic.css">
  <link rel="stylesheet" href="css/bootstrap.min.css">
  <link rel="stylesheet" href="css/snippetbase.css">
  <style>
    .media-title {
      font-size: 0.85rem;
      text-align: center;
      margin-top: 5px;
    }
    .media-block {
      margin-bottom: 20px;
    }
    .media-img, .media-video {
      width: 100%;
      height: auto;
      border-radius: 10px;
    }
    .video-thumbnail {
      position: relative;
    }
    .video-thumbnail::after {
      content: "▶";
      font-size: 2rem;
      color: white;
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      pointer-events: none;
      text-shadow: 0 0 8px black;
    }
    .section-heading {
      font-weight: 600;
      margin-bottom: 1rem;
      text-align: center;
    }
    .reference-links a {
      margin-right: 15px;
    }
    .fixed-columns {
      display: flex;
      flex-direction: row;
      flex-wrap: nowrap;
      gap: 20px;
    }
    .fixed-columns > div {
      width: 50%;
    }

    @media (max-width: 768px) {
      .media-img, .media-video {
        max-height: 120px;
      }
      .media-title {
        font-size: 0.75rem;
      }
    }
  </style>
</head>
<body>
<center>
  <div class="header">
    <div class="search-box" align="left">
	<img src="images/ExSMuV.png" height="30" width="100">
  <input type="text" id="search-input" placeholder="Search here">
  <button id="search-button"><span>🔍</span></button>
  <select name="workspace" id="workspace" style="
  padding: 8px 14px;
  font-size: 16px;
  font-family: 'Segoe UI', sans-serif;
  background-color: #f8f9fa;
  border: 1px solid #ccc;
  border-radius: 8px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
  color: #333;
  cursor: pointer;
  transition: all 0.2s ease-in-out;
">
	<option value="Grid">Grid</option>
	<option value="Canvas" selected="selected">Canvas</option>
	</select>
</div>
</div>
</center>
    <!--<div class="menu">
      <button class="active">Home</button>
      <button>About</button>
      <button>Help</button>
      <button>Contact</button>
    </div>-->
  </div>
  <div class="canvas-wrapper" id="canvas-wrapper">
  <!-- Loader spinner -->
  <div id="loader" class="loader" style="display: none;"></div>

  <div class="graph-container" id="graph-container">
    <svg id="lines-svg"></svg>
    <div id="nodes-container"></div>
  </div>
</div>


  <div class="zoom-controls">
    <button id="zoom-in">➕</button>
    <button id="zoom-out">➖</button>
    <button id="reset">🏠</button>
  </div>
  
  
<div class="split right">
<div class="centered"></div>
</div>

  <script src="js/canvas.js"></script>
  <script>
function loadMoreDetails(title, query) {

    const xhr = new XMLHttpRequest();
    let url = 'caller/cccallme.php?title=' + encodeURIComponent(title);
    if (query) url += '&q=' + encodeURIComponent(query);
    xhr.open('GET', url, true);
    xhr.onload = function () {
        if (xhr.status === 200) {
            document.querySelector('.split.right .centered').innerHTML = xhr.responseText;
        } else {
            console.error('Failed to load details');
        }
    };
    xhr.onerror = function () {
        console.error('An error occurred during the request');
    };
    xhr.send();
}
</script>
<script>
document.getElementById("workspace").addEventListener("change", function() {
    const value = this.value;

    // Define redirection URLs for each option
    const routes = {
        "Grid": "index.php",
        "Canvas": "index2.php"
    };

    // Redirect to the corresponding page
    if (routes[value]) {
        window.location.href = routes[value];
    }
});
</script>
</body>
</html>