# MCP server

`scripts/mcp.php` exposes the network to an AI agent over the Model Context
Protocol: JSON-RPC 2.0 on stdin/stdout, one message per line.

An agent works exactly the way Pages CMS works — it edits files in `content/`,
`config/` and `data/` and commits them to `main`. The VPS timer picks the commit
up, validates, builds and publishes. One write path, one history, nothing of its
own to drift out of step.

```
claude mcp add minicms -- php /path/to/scripts/mcp.php
```

The repository also ships `.mcp.json`, so Claude Code offers the server to
anyone who opens the project.

## Writes, commits and pushes

| Variable | Effect |
| -------- | ------ |
| `MCP_GIT_AUTHOR` | `Имя <mail@example.com>` on the commits. Defaults to the repository's git identity. |
| `MCP_GIT_PUSH=1` | Push after every commit. Off by default. |

Every write commits only the files it touched — the working tree may hold
somebody else's work in progress, and an agent has no business sweeping that
into its commit. Without `MCP_GIT_PUSH` the work stays local, so nothing an
agent does reaches the live sites until a human pushes.

There are no accounts to configure: the repository is the permission boundary.
Whoever can run the server can already edit the files.

## Tools

| Tool | Does |
| ---- | ---- |
| `sites_list` | Sites, domains, languages, hreflang, URL prefixes |
| `page_types` | Field schema per page type, plus the block types |
| `pages_list` | Pages of a site, filtered by status or type |
| `page_read` | Front matter, body, open issues, and the file **hash** |
| `products_list`, `product_read` | The product database and where each product is used |
| `seo_audit` | Title and description lengths, thin text, missing translations, orphan pages |
| `translations_report` | What exists in which market, grouped the way hreflang groups it |
| `history` | Recent commits: who, when, which files — the history is git's |
| `page_save` | Write a page and commit it |
| `page_publish` | `publish` / `unpublish`: flip the status and commit |
| `product_save` | Product and affiliate links |
| `site_save` | Create a country or change its settings |
| `validate` | Business data, CMS schema and content checks, structured |
| `build` | Build one site into `dist/` and return the log |
| `backup` | Encrypted archive of the sources and images |

A normal editing loop:

```
sites_list → page_types → pages_list → page_read → page_save → page_publish
```

`page_read` returns the file hash; `page_save` takes it back. A page that
changed underneath — an editor in Pages CMS, a `git pull` — is refused rather
than overwritten. That is the whole concurrency story, and it is the same one
Pages CMS relies on.

Nobody approves what the agent wrote: `page_publish` sets the status, the
validation and the build decide whether the release goes out.

## Two things to know about the design

**Nothing may write to stdout.** That stream is the protocol. Every child
process — git, Cecil — is captured through `Builder::run`, and PHP's own errors
are pinned to stderr at the top of the file. A stray `echo` in this layer would
break every agent session.

**Dates.** YAML parses `date: 2026-02-03` into a timestamp, and an agent that
echoes back what it read would write the timestamp. `page_read` and `page_save`
normalise date fields to `Y-m-d` in both directions.

## Checking it by hand

The server is a plain line protocol, so a pipe is enough:

```sh
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{}}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"sites_list","arguments":{}}}' \
  | php scripts/mcp.php
```
