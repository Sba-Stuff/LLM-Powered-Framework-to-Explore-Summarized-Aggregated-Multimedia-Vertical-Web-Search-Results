<?php

include("verticalsfetcher.php");
#print_r(BingWebResults("harry potter", $numResults = 10, $debug = false));
#print_r(BingWebResults("harry potter", $numResults = 10, $debug = true));
#print_r(BingRelatedSearches("transformers", $debug = true));
print_r(getBingSuggestions("transformers", $debug= true));
?>