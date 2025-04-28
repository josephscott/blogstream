# Blogstream

This takes the XML blog ping flow from <a href="http://blo.gs/">http://blo.gs/</a> and provides a JSON HTTP Server-Sent Events URL.

## Usage

Start the server: `php server.php start -d`

View the JSON SSE: `curl -H 'Accept: text/event-stream' -N http://localhost:39999/sse`
