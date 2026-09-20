const data = [];

const container = document.getElementById("nodes-container");
const svg = document.getElementById("lines-svg");
const nodeMap = {};
const lineMap = [];

function drawLines() {
  svg.innerHTML = "";
  data.forEach(start => {
    start.links.forEach(linkId => {
      const end = data.find(d => d.id === linkId);
      if (end) {
        const line = document.createElementNS("http://www.w3.org/2000/svg", "line");
        const startX = start.x + 90;
        const startY = start.y + 50;
        const endX = end.x + 90;
        const endY = end.y + 50;

        line.setAttribute("x1", startX);
        line.setAttribute("y1", startY);
        line.setAttribute("x2", endX);
        line.setAttribute("y2", endY);
        line.setAttribute("stroke", "#ccc");
        line.setAttribute("stroke-width", "2");

        svg.appendChild(line);
        lineMap.push(line);
      }
    });
  });
}

// Drag nodes
let dragNode = null;
let offsetX, offsetY;

container.addEventListener("mousedown", e => {
  if (e.target.closest(".node")) {
    dragNode = e.target.closest(".node");
    const id = dragNode.getAttribute("data-id");
    const nodeData = data.find(d => d.id == id);
    const rect = dragNode.getBoundingClientRect();
    offsetX = e.clientX - rect.left;
    offsetY = e.clientY - rect.top;

    document.addEventListener("mousemove", onDrag);
    document.addEventListener("mouseup", stopDrag);
  }
});

function onDrag(e) {
  if (!dragNode) return;
  const id = dragNode.getAttribute("data-id");
  const nodeData = data.find(d => d.id == id);
  const newX = e.clientX - offsetX;
  const newY = e.clientY - offsetY;

  nodeData.x = newX;
  nodeData.y = newY;

  dragNode.style.left = `${newX}px`;
  dragNode.style.top = `${newY}px`;

  drawLines();
}

function stopDrag() {
  dragNode = null;
  document.removeEventListener("mousemove", onDrag);
  document.removeEventListener("mouseup", stopDrag);
}

// Zoom & Pan
let scale = 1;
let translateX = 0;
let translateY = 0;

const graphContainer = document.getElementById("graph-container");
const canvasWrapper = document.getElementById("canvas-wrapper");

function updateTransform() {
  graphContainer.style.transform = `translate(${translateX}px, ${translateY}px) scale(${scale})`;
}

document.getElementById("zoom-in").addEventListener("click", () => {
  scale *= 1.1;
  updateTransform();
});

document.getElementById("zoom-out").addEventListener("click", () => {
  scale /= 1.1;
  updateTransform();
});

document.getElementById("reset").addEventListener("click", () => {
  scale = 1;
  translateX = 0;
  translateY = 0;
  updateTransform();
});

let isPanning = false;
let startX, startY;

canvasWrapper.addEventListener("mousedown", (e) => {
  if (e.target.closest(".node")) return;
  isPanning = true;
  startX = e.clientX - translateX;
  startY = e.clientY - translateY;
  canvasWrapper.style.cursor = "grabbing";
});

canvasWrapper.addEventListener("mousemove", (e) => {
  if (!isPanning) return;
  translateX = e.clientX - startX;
  translateY = e.clientY - startY;
  updateTransform();
});

canvasWrapper.addEventListener("mouseup", () => {
  isPanning = false;
  canvasWrapper.style.cursor = "grab";
});

canvasWrapper.addEventListener("mouseleave", () => {
  isPanning = false;
  canvasWrapper.style.cursor = "grab";
});

canvasWrapper.addEventListener("wheel", (e) => {
  e.preventDefault();
  const delta = e.deltaY < 0 ? 1.1 : 0.9;
  scale *= delta;
  updateTransform();
});

// Spiral layout search
document.getElementById("search-button").addEventListener("click", () => {
  const query = document.getElementById("search-input").value.trim();
  if (!query) return;

  document.getElementById("loader").style.display = "block";

  fetch(`searchresults.php?query=${encodeURIComponent(query)}`)
    .then(res => res.text())
    .then(html => {
      document.getElementById("loader").style.display = "none";
      container.innerHTML = "";

      const tempDiv = document.createElement("div");
      tempDiv.innerHTML = html;
      const snippets = tempDiv.querySelectorAll(".snippet");

      const spacing = 120;
      const angleStep = Math.PI / 6;
      const centerX = 600, centerY = 400;

      snippets.forEach((snippet, i) => {
        const title = snippet.querySelector("h3")?.innerText || "Untitled";
        const desc = snippet.querySelector("p")?.innerText || "No description";
        const images = Array.from(snippet.querySelectorAll(".thumbnail-container img")).slice(0, 5);
        const videos = Array.from(snippet.querySelectorAll("video")).slice(0, 2);

        const angle = i * angleStep;
        const radius = spacing * Math.sqrt(i);
        const newX = centerX + radius * Math.cos(angle);
        const newY = centerY + radius * Math.sin(angle);

        const newId = data.length + 1;
        const img1 = images[0]?.src || "https://picsum.photos/seed/default1/300/200";
        const img2 = videos[1]?.getAttribute("poster") || img1;

        const newNode = {
          id: newId,
          title: title,
          desc: desc,
          img: img1,
          img2: img2,
          x: newX,
          y: newY,
          links: []
        };

        data.push(newNode);

        const node = document.createElement("div");
        node.className = "node";
        node.style.left = `${newX}px`;
        node.style.top = `${newY}px`;
        node.setAttribute("data-id", newId);

        const snippetClone = snippet.cloneNode(true);
        node.appendChild(snippetClone);

        container.appendChild(node);
        nodeMap[newId] = node;
      });

      // Optional full mesh connection
      // data.forEach(d1 => {
      //   d1.links = data.filter(d2 => d2.id !== d1.id).map(d2 => d2.id);
      // });
      // drawLines();

    })
    .catch(err => {
      console.error("Search error:", err);
    });
});
