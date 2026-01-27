# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is `keboola/dockerbundle` - a PHP library that orchestrates execution of Docker containers as part of Keboola's data pipeline platform. It handles the complete lifecycle: image preparation, configuration injection, container execution, state management, and resource limiting.

## Commands

**IMPORTANT:** All commands (composer, phpunit, phpcs, phpstan, etc.) should be run inside the Docker development container:

```bash
docker compose run --rm dev <command>
```

For example:
```bash
docker compose run --rm dev composer phpstan
docker compose run --rm dev vendor/bin/phpunit
```

### Running Tests

```bash
# All tests (requires environment variables set)
composer tests

# Specific test suites
vendor/bin/phpunit --testsuite=base-tests
vendor/bin/phpunit --testsuite=runner-tests
vendor/bin/phpunit --testsuite=runner-tests-2
vendor/bin/phpunit --testsuite=container-tests

# Backend-specific tests
vendor/bin/phpunit --testsuite=abs-tests      # Azure Blob Storage
vendor/bin/phpunit --testsuite=gcs-tests      # Google Cloud Storage
vendor/bin/phpunit --testsuite=bigquery-tests # BigQuery

# Single test file
vendor/bin/phpunit Tests/Docker/Container/ContainerTest.php

# Single test method
vendor/bin/phpunit --filter testMethodName Tests/Path/To/TestFile.php
```

### Code Quality

```bash
# Run all checks (validation, phpcs, phpstan)
composer check

# Run everything including tests
composer ci

# Individual tools
composer phpcs      # Check coding standards
composer phpcbf     # Fix coding standards automatically
composer phpstan    # Static analysis
```

### Development Environment

```bash
# Build and start Docker test environment
docker-compose up

# Run tests in Docker
docker-compose run tests vendor/bin/phpunit

# Development shell with mounted volumes
docker-compose run dev bash
```

## Test Environment Setup

Tests require real Keboola Storage API credentials and AWS resources. Copy `.env` to `.env.local` and set:

- `STORAGE_API_TOKEN` - Keboola Storage API token (master access)
- `STORAGE_API_TOKEN_MASTER` - Master token for admin operations
- `STORAGE_API_TOKEN_READ_ONLY` - Read-only token for limited tests
- `STORAGE_API_TOKEN_NETWORK_POLICY` - Token with network policy enabled
- `AWS_ECR_*` - AWS ECR credentials and registry info
- `AWS_KMS_TEST_KEY` - AWS KMS key for encryption tests
- `DOCKERHUB_PRIVATE_*` - Private Docker Hub credentials (optional)
- `RUNNER_MIN_LOG_PORT` / `RUNNER_MAX_LOG_PORT` - Port range for GELF logging

See `Tests/bootstrap.php` for required environment variables.

## Architecture

### Core Components

**Runner (`src/Docker/Runner.php`)**
- Main orchestrator for job execution
- Manages the complete pipeline: input mapping → container execution → output mapping
- Handles state persistence and artifact management
- Coordinates processor pipeline (before → main → after)
- Key methods: `run()`, `runRow()`, `runComponent()`, `runImages()`

**Container (`src/Docker/Container.php`)**
- Wraps Docker CLI operations
- Builds and executes `docker run` commands with proper limits and environment
- Handles output capture (direct output or GELF logging)
- Detects OOM and timeout conditions
- Always removes containers in finally block

**Image (`src/Docker/Image.php` - abstract)**
- Base class for different registry types (DockerHub, QuayIO, ECR, ReplicatedRegistry)
- Validates image digests before execution
- Implements retry logic with exponential backoff
- Subclasses implement `pullImage()` for registry-specific authentication

**ImageFactory (`src/Docker/ImageFactory.php`)**
- Factory for creating appropriate Image instances based on component type
- Supports `dockerhub`, `quayio`, `aws-ecr`, and replicated registry redirection
- Handles air-gapped/private registry scenarios via `USE_REPLICATED_REGISTRY` env var

**JobDefinition (`src/Docker/JobDefinition.php`)**
- Encapsulates a single job to execute: component, configuration, state, row ID
- Normalizes configuration via `Container` configuration validator

### Runner Support Classes (`src/Docker/Runner/`)

- **ImageCreator** - Prepares all images (before processors, main, after processors) and fetches component specs from Storage API
- **ConfigFile** - Generates configuration files injected into containers (JSON/YAML)
- **StateFile** - Manages persistent component state across runs, reads from `/data/in/state`, persists back to Storage API
- **Environment** - Constructs KBC_* environment variables passed to containers (RUNID, PROJECTID, CONFIGID, etc.)
- **Limits** - Enforces CPU and memory constraints based on component definitions and project limits
- **WorkingDirectory** - Manages temporary `/data` and `/tmp` directories with cleanup
- **Authorization** - Manages OAuth credentials and decrypts authorization data
- **Output** - Processes container output and artifacts

### Configuration System (`src/Docker/Configuration/`)

Uses Symfony Config component with ConfigurationInterface pattern:

- **Container** - Validates runtime configuration (parameters, storage mapping, processors, artifacts, timeout, backend)
- **Image** - Image definition schema (type, URI, tag, digest, credentials)
- **State** - Component state schema
- **Authorization/AuthorizationDefinition** - OAuth/authorization config

### Registry Implementations (`src/Docker/Image/`)

- **DockerHub** - Standard Docker Hub images
- **QuayIO** - Quay.io repository images
- **AWSElasticContainerRegistry** - AWS ECR with STS/ECR authentication and automatic token generation
- **ReplicatedRegistry** - Generic registry for air-gapped environments (e.g., Google Artifact Registry)

### Exception Hierarchy (`src/Exception/`)

- **ApplicationException** - Server/application errors (HTTP 500, component responsibility)
- **UserException** - Client/configuration errors (HTTP 400, user responsibility)
- **OutOfMemoryException** - Memory limit exceeded
- **LoginFailedException** - Registry authentication failure
- **ExternalWorkspaceException** - Workspace provisioning failure

## Data Flow

```
Runner.run(jobDefinitions)
  │
  └─ For each JobDefinition:
      │
      ├─ runRow()
      │   ├─ Create temporary working directory
      │   ├─ Create JobScopedEncryptor
      │   ├─ Create ConfigFile (injected into container)
      │   ├─ prepareDataLoader() - Setup input/output mapping, staging workspace
      │   │   ├─ Create StagingWorkspace (temporary tables)
      │   │   ├─ InputDataLoader (loads data from Storage)
      │   │   └─ OutputDataLoader (stages output)
      │   │
      │   └─ runComponent()
      │       ├─ Load input data via InputDataLoader
      │       │
      │       └─ runImages()
      │           ├─ ImageCreator.prepareImages() - before, main, after processors
      │           ├─ Create Limits object
      │           │
      │           └─ For each Image (in priority order):
      │               ├─ Create Environment variables
      │               ├─ Prepare config file
      │               ├─ Image.prepare() - validate digest, pull if needed
      │               ├─ Container.run() - execute docker process
      │               ├─ Handle state file (main image only)
      │               ├─ Download/upload artifacts
      │               └─ Move output to input for next processor
      │
      ├─ Store output via OutputDataLoader
      └─ Persist state back to Storage API
```

## Key Patterns

- **Factory Pattern**: ImageFactory creates appropriate registry implementations
- **Strategy Pattern**: Different OutputFilter implementations, registry strategies
- **Template Method**: Image base class defines pullImage() template
- **Dependency Injection**: Constructor-based DI throughout
- **Configuration Tree Builder**: Symfony Config component for validation

## Important Implementation Notes

### State Management
- State persists between job runs at both config-level and row-level
- Input/output table and file state is merged with component state
- Only saved when component configuration exists (not for one-off runs)

### Resource Limits
- CPU and memory enforced via Docker flags
- Based on: component definitions, project limits, instance capabilities
- Container types: xsmall, small, medium, large
- Supports pay-as-you-go pricing model

### Security
- Secret values redacted from logs via OutputFilter
- JobScopedEncryptor for per-job encryption
- Token forwarding based on component permissions
- OAuth credentials decrypted via Authorization helper

### Logging
- Two modes: direct output or GELF (Graylog Extended Log Format)
- ContainerLogger for container-level logging
- DockerProcessor adds container info to logs
- Monolog integration with multiple handlers

### Registry Support
- Recent work added generic ReplicatedRegistry support for air-gapped deployments
- Environment variables: `USE_REPLICATED_REGISTRY`, `REPLICATED_REGISTRY_URL`
- Replaces hardcoded registry URLs with configurable endpoints
- Used for Google Artifact Registry (GAR) and other private registries

## Coding Standards

- PHP 8.2+ required
- Uses Keboola Coding Standard (PSR-12 based)
- PHPStan at max level with baseline for legacy issues
- Type hints generally required (some exceptions via phpcs.xml exclusions)
- Strict types declared in all files
