# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 1.x     | ✅        |

## Reporting a Vulnerability

If you discover a security vulnerability in php-image-guard, please report it
responsibly:

1. **Email**: <rcsofttech85@gmail.com>
2. **Subject**: `[SECURITY] php-image-guard — <brief description>`

**Do NOT open a public GitHub issue for security vulnerabilities.**

## Response Timeline

- **Acknowledgment**: Within 48 hours
- **Assessment**: Within 7 days
- **Fix release**: Within 30 days of confirmation

## Scope

This package processes image files via GD and Imagick extensions. Relevant
security concerns include:

- Crafted image files triggering resource exhaustion
- Path traversal via user-supplied file paths
- Denial of service through unbounded retry loops
