# Application Submission Checklist

| # | Name | Note | &#9745; | Example | 
| --- | ----- | ---- | ---- | ---- |  
| 1 | **Application name** | Do not use the word *extractor* or *writer* in your app.  | &#9744; | `iTunes` | 
| 2 | **Application type** | One of `extractor`, `writer`, `application` | &#9744; | `extractor` |
| 3 | **Short description** | One sentence describing your app. | &#9744; | `Tailor-made predictive models (recommendation engines, propensity models and many more) in R` |
| 4 | **Full description** | Markdown or a link to a markdown document | &#9744; |  |
| 5 | **Application icon URL** | PNG, 32x32px and 64x64px sizes on a public URL | &#9744; | `https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-32-1.png` | 
| 6 | **Vendor full name** | Will be visible to end-users | &#9744; | `Company LTD` | 
| 7 | **Vendor address** |  Will be visible to end-users  | &#9744; | `1 Connected Way, BigTown, CS` | 
| 8 | **Email address** | Will be visible to end-users and we will send error notifications to this address | &#9744; | `info@company.com` | 
| 9 | **License agreement URL** | Can be included in your public source repository | &#9744; | `https://github.com/org/reponame/master/blob/LICENSE.md` |
| 10 | **Docker image URL** | Currently supported are DockerHub (public and private) ad Quay (public) | &#9744; | `https://hub.docker.com/r/keboola/docker-demo` |
| 11 | **Docker image tag** | Tag of the image in the Docker repository, typically `latest` or `master` | &#9744; | `latest` |
| 12 | **Required memory**  | Maximum memory your image will use | &#9744; | `512M` |
| 13 | **Processing timeout**  | Maximum processing time in seconds | &#9744; | `3600` |
| 14 | **Configuration format**  | Format to store configuration file, state file and all manifests, `yaml` or `json` | &#9744; | `yaml` |
| 15 | **Streaming logs**  | STDOUT will immediately forward to Storage API events, `true` or `false` | &#9744; | `false` |
| 16 | **Encryption** | Application uses encrypted parameters, `true` or `false` | &#9744; | `false` |
| 17 | **Token forwarding** | Application needs Storage API token and its details, `true` or `false` | &#9744; | `false` |
| 18 | **Component documentation** | Instructions to help end-user configure the application | &#9744; | `https://github.com/org/reponame/master/blob/CONFIGURATION.md` |
| 19 | **UI options** | Which UI helpers to use, any of `tableInput`, `tableOutput`, `fileInput`, `fileOutput`| &#9744; | `tableInput, tableOutput` |

 Note: If required, all payment information should be described in **Component documentation**.
