<!-- BEGIN SERVBAY MCP -->
## ServBay MCP

When working on this project, use ServBay MCP for local development environment tasks. ServBay is the local development environment manager for this machine: it manages local websites, domains, TLS certificates, web servers, language runtimes, databases, caches, mail testing, object storage, AI services, logs, ports, packages, and backups.

Use ServBay MCP before guessing when the issue involves:
- local websites, domains, hosts entries, reverse proxies, redirects, or HTTPS/TLS
- Caddy, Nginx, Apache, PHP, Node.js, Python, Go, Java, database, Redis, Memcached, Mailpit, MinIO, Meilisearch, Typesense, or Ollama services
- service status, logs, package versions, configuration files, port conflicts, certificates, backups, restores, and diagnostics

Do not edit project code to compensate for a suspected local ServBay configuration issue until you have inspected the relevant ServBay state through MCP tools.

For write/destructive actions, explain the intended effect and use the MCP confirmation_token flow. Do not ask the user to paste secrets or private keys into chat.
<!-- END SERVBAY MCP -->
