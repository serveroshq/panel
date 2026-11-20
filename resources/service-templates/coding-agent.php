<?php

return [
    'name' => 'Coding agent',
    'category' => 'Self-hosted',
    'description' => 'Claude Code, Codex and OpenCode in a container on your machine, for the Agents page and workflows: they work on your code, check what they changed in a browser, and open pull requests. Your keys and code stay on your server.',
    'icon' => 'bot',
    'kind' => 'compose',
    'port' => 7070,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Add a Run agent step to a workflow and choose this container. Connect your Anthropic or OpenAI key in Settings › AI and MCP and it’s handed to each run; or set ANTHROPIC_API_KEY or OPENAI_API_KEY here. Private repositories clone through ServerOS’s GitHub App, or set GH_TOKEN. The first start installs the agents and a headless Chromium for them to check their work in, which takes a few minutes; each restart updates them.',
    'env' => [
        ['key' => 'ANTHROPIC_API_KEY', 'label' => 'Anthropic API key (optional)', 'hint' => 'For Claude Code. Not needed if the workspace’s key is connected in Settings › AI and MCP.', 'secret' => true],
        ['key' => 'OPENAI_API_KEY', 'label' => 'OpenAI API key (optional)', 'hint' => 'For Codex. Not needed if the workspace’s key is connected in Settings › AI and MCP.', 'secret' => true],
        ['key' => 'OLLAMA_URL', 'label' => 'Ollama address (optional)', 'hint' => 'For OpenCode on a local model, so nothing leaves the machine. Defaults to Ollama on this machine, http://host.docker.internal:11434: start Ollama with OLLAMA_HOST=0.0.0.0 so containers can reach it, and keep port 11434 closed in the firewall.'],
        ['key' => 'GH_TOKEN', 'label' => 'GitHub token (optional)', 'hint' => 'Only if ServerOS’s GitHub App can’t reach the repository: a fine-grained token with contents and pull requests read and write.', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  agent:
    image: node:22-bookworm
    restart: unless-stopped
    init: true
    environment:
      ANTHROPIC_API_KEY: ${ANTHROPIC_API_KEY}
      OPENAI_API_KEY: ${OPENAI_API_KEY}
      GH_TOKEN: ${GH_TOKEN}
      OLLAMA_URL: ${OLLAMA_URL}
      NPM_CONFIG_PREFIX: /opt/agent
      PATH: /opt/agent/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
      DISABLE_AUTOUPDATER: "1"
      # A headless Chromium, so agents can look at what they changed.
      PLAYWRIGHT_BROWSERS_PATH: /opt/agent/browsers
      NODE_PATH: /opt/agent/lib/node_modules
    # Install (or update) the agents and the browser, then wait for tasks to
    # run them. Runs wait while .installing is there.
    command: ["sh", "-c", "touch /opt/agent/.installing; npm install -g @anthropic-ai/claude-code @openai/codex opencode-ai playwright && playwright install --with-deps chromium || echo Could not install or update the agents; rm -f /opt/agent/.installing; exec sleep infinity"]
    # Ollama on the machine itself, for OpenCode on a local model.
    extra_hosts:
      - "host.docker.internal:host-gateway"
    volumes:
      - tools:/opt/agent
      - home:/root
    deploy:
      resources:
        limits:
          memory: 4g
volumes:
  tools:
  home:
YAML,
    ],
];
