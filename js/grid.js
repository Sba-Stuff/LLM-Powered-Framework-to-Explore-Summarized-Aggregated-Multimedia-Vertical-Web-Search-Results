document.getElementById("search-button").addEventListener("click", () => {
  const query = document.getElementById("search-input").value.trim();
  const container = document.getElementById("nodes-container");

  if (!query) return;

  container.innerHTML = "<div>Loading...</div>";

  fetch(`searchresults.php?query=${encodeURIComponent(query)}`)
    .then(res => res.text())
    .then(html => {
      container.innerHTML = ""; // clear previous results

      const tempDiv = document.createElement("div");
      tempDiv.innerHTML = html;

      const snippets = tempDiv.querySelectorAll(".snippet");

      snippets.forEach(snippet => {
        container.appendChild(snippet); // directly add snippet to grid
      });
    })
    .catch(err => {
      console.error("Search error:", err);
      container.innerHTML = "<div>Error loading results</div>";
    });
});


window.addEventListener("DOMContentLoaded", () => {
  const params = new URLSearchParams(window.location.search);
  const query = params.get("query");

  if (query) {
    document.getElementById("search-input").value = query;
    document.getElementById("search-button").click(); // Trigger search
  }
});