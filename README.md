# Runner Library




[![Build Status](https://dev.azure.com/keboola-dev/docker-runner/_apis/build/status/keboola.docker-bundle?branchName=master)](https://dev.azure.com/keboola-dev/docker-runner/_build/latest?definitionId=1&branchName=master)

Library for components for running Docker images:
- [Runner Sync API](https://github.com/keboola/runner-sync-api)
- [Jobs Runner](https://github.com/keboola/docker-runner-jobs)

See [documentation](https://developers.keboola.com/extend/docker-runner/).

## Running tests locally
Use `test-cf-stack.json` to create resources, set environment variables (see `.env.template`) and run `docker-compose up`.
Dockerfile and docker-compose.yml are used only for development purposes.
Keboola Connection Component for running Docker images - see [documentation](https://developers.keboola.com/extend/docker-runner/).

## License

MIT licensed, see [LICENSE](./LICENSE) file.
