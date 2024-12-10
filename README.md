# Runner Library

[![Build Status](https://dev.azure.com/keboola-dev/job-runner/_apis/build/status%2Fkeboola.job-runner?branchName=main)](https://dev.azure.com/keboola-dev/job-runner/_build/latest?definitionId=5&branchName=main)

Library for components for running Docker images:
- [Runner Sync API](https://github.com/keboola/runner-sync-api)
- [Jobs Runner](https://github.com/keboola/docker-runner-jobs)

See [documentation](https://developers.keboola.com/extend/docker-runner/).

## Running tests locally
Use `test-cf-stack.json` to create resources, set environment variables (see `.env.template`) and run `docker-compose up`.
Dockerfile and docker-compose.yml are used only for development purposes.
Keboola Connection Component for running Docker images - see [documentation](https://developers.keboola.com/extend/docker-runner/).

If you want to run Workspace tests, please turn off the NetworkPolicy for the STORAGE_API_TOKEN with the following command:
```
curl -X POST --location "https://connection.keboola.com/manage/commands" \
    -H "X-KBC-ManageApiToken: {MANAGE_TOKEN}" \
    -H "Content-Type: application/json" \
    -d '{
          "command": "manage:tmp:migrate-network-policy",
          "parameters": [
            "{BACKEND_ID}",
            "--orgIds={ORG_ID}",
            "--remove",
            "--force" // remove this param for dry-run
          ]
        }'
```

## License

MIT licensed, see [LICENSE](./LICENSE) file.
