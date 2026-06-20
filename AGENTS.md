# Agent Instructions for Sinclear Beyond API

## OpenAPI Documentation
It is elementarily important for the collaboration of SinclearAPI with all clients that the `openapi.yaml` is always up to date.

**Requirement:**
After every change to the API (routes, controllers, DTOs, or ResourceRegistry), you MUST:
1. Verify the accuracy and completeness of the `openapi.yaml` file.
2. Ensure that all moment-by-moment existing API endpoints and functions are fully and correctly reflected in the specification.

## Documentation
The `docs/` directory contains developer-facing documentation for the API.

**Requirement:**
After every change to the API (routes, controllers, DTOs, or ResourceRegistry), you MUST:
1. Update the relevant documentation files in `docs/` to reflect the changes.
2. Ensure that all flows, endpoints, and configuration are accurately documented.

## Security and File Access
To ensure that secrets inside the .env file or log files cannot be read by anyone, a `.htaccess` is present to secure the API.

**Requirement:**
After every change to the API (routes, controllers, DTOs, or ResourceRegistry), you MUST:
1. Verify the accuracy and completeness of the `.htaccess` file for the security of the project.
2. Ensure that all files in the project folder have the correct access rights or denials set in the `.htaccess` file to protect the secrets of the API and it's code and config.

## Coding Standards
- Use PHP 8.4 features where appropriate.
- Follow the established CRUD pattern using `ResourceRegistry.php` for standard resources.
- Ensure all endpoints are secured with the appropriate Policy classes.
