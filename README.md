# OGX.AI

OGX.AI is a lightweight MCP (Model Context Protocol) endpoint designed to teach AI agents how to generate valid OML (Object Markup Language) files for OGX.JS.

The goal of this project is not to generate OML on the server. Instead, it provides structured knowledge that allows AI models such as GPT, Claude, Grok, Cursor, Copilot, and other MCP-compatible agents to learn the OML language and generate OML themselves.

## Philosophy

The AI model remains responsible for:

- Understanding user requirements
- Designing interfaces
- Generating OML markup

OGX.AI provides:

- OML language specification
- Component catalog
- OML examples
- Validation rules
- Optional OML validation endpoint

This keeps the server simple and allows any AI model to become an OML-aware developer.

## Resources

The MCP server exposes machine-readable resources describing the language.

### Overview

General information about OML and OGX.JS.

```
oml://overview
```

### Specification

Language syntax, rules, and structure.

```
oml://specification
```

### Components

Available OML components, properties, datatypes, and constraints.

```
oml://components
```

### Examples

Reference OML examples demonstrating common patterns and layouts.

```
oml://examples
```

## Validation

The MCP server may expose a validation tool:

```
validate_oml()
```

The purpose of validation is to allow agents to verify generated OML and self-correct errors before returning a final result.

Example workflow:

```text
User Request
      |
      v
AI Agent
      |
      v
Read OML Specification
Read Components
Read Examples
      |
      v
Generate OML
      |
      v
validate_oml()
      |
      v
Fix Errors
      |
      v
Return Valid OML
```

## Repository Structure

```text
OGX.AI/
|
├── mcp.php
|
├── resources/
│   ├── overview.json
│   ├── specs.json
│   ├── components.json
│   ├── examples.json
│   └── validator-rules.json
|
└── README.md
```

## Design Goals

- AI-first
- Machine-readable
- Minimal dependencies
- Language-driven
- Open source
- Vendor neutral
- Human documentation optional

The repository itself serves as the source of truth for OML. AI agents consume the exposed resources directly through MCP and generate OML based on the language specification and available components.

## About OML

OML (Object Markup Language) is the UI description language used by OGX.JS.

OML is designed to be:

- Declarative
- Component-based
- Human readable
- AI friendly
- Easy to validate
- Easy to generate

## License

MIT

