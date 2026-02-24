# Runner Library

[![Build Status](https://dev.azure.com/keboola-dev/job-runner/_apis/build/status%2Fkeboola.job-runner?branchName=main)](https://dev.azure.com/keboola-dev/job-runner/_build/latest?definitionId=5&branchName=main)

Library for components for running Docker images:
- [Runner Sync API](https://github.com/keboola/runner-sync-api)
- [Jobs Runner](https://github.com/keboola/docker-runner-jobs)

See [documentation](https://developers.keboola.com/extend/docker-runner/).

## Running tests locally

### 1. Set up environment variables

The tests require an ECR repository, an IAM user with access keys, and a KMS key. Use the provided `test-cf-stack.json` CloudFormation template to create the stack in the AWS console beforehand.

Create `.env.local`:

```bash
cat <<EOF > .env.local
# Keboola Storage API
STORAGE_API_URL=https://connection.keboola.com
STORAGE_API_TOKEN=              # regular token for your Keboola project
STORAGE_API_TOKEN_MASTER=       # master token for your Keboola project
STORAGE_API_TOKEN_READ_ONLY=    # read-only token for your Keboola project
STORAGE_API_TOKEN_NETWORK_POLICY=

# AWS ECR
# RepositoryUrl, Region — CloudFormation stack > Outputs tab
# IAM access keys — CloudFormation stack > Resources tab > DockerRunnerUser > IAM console > Security credentials > Create access key
AWS_ECR_REGISTRY_URI=           # RepositoryUrl
AWS_ECR_REGISTRY_REGION=        # Region
AWS_ECR_REGISTRY_ACCOUNT_ID=    # first part of the registry URI
AWS_ECR_ACCESS_KEY_ID=          # IAM user access key
AWS_ECR_SECRET_ACCESS_KEY=      # IAM user secret key

# AWS KMS
# KMSKey, Region — CloudFormation stack > Outputs tab
AWS_KMS_TEST_KEY=               # KMSKey
AWS_KMS_REGION=                 # Region

# Docker Hub private registry (optional)
DOCKERHUB_PRIVATE_USERNAME=
DOCKERHUB_PRIVATE_PASSWORD=
DOCKERHUB_PRIVATE_SERVER=https://index.docker.io/v1/

# Quay.io private registry (optional)
QUAYIO_PRIVATE_USERNAME=
QUAYIO_PRIVATE_PASSWORD=

# Log port range for GELF logging
RUNNER_MIN_LOG_PORT=12202
RUNNER_MAX_LOG_PORT=13202

# Command to get the Docker host IP inside the container
RUNNER_COMMAND_TO_GET_HOST_IP="ip -4 addr show docker0 | grep -Po 'inet \K[\d.]+'"
EOF
```

Fill in the values and save.

### 2. Run tests

```bash
docker compose run --rm dev composer ci
```

## Workspace tests

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
