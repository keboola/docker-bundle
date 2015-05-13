# HOWTO

This guide will show you how you can create your own Docker image that can run in Keboola Connection. To see it in action see `docker-demo` image on [Dockerhub](https://registry.hub.docker.com/u/keboola/docker-demo/) and the two source repositories on GitHub: [keboola/docker-demo-app](https://github.com/keboola/docker-demo-app) and [keboola/docker-demo-docker](https://github.com/keboola/docker-demo-docker).

## Architecture

You can encapsulate any app into an Docker image following a set of simple rules that will allow you to integrate the app into Keboola Connection.

There is a predefined interface with the Docker bundle consisting of a folder structure and a serialized configuration file. The app usually grabs some data (tables or files) from Storage, processes them using parameters from the configuration and then stores the data back to Storage. Docker bundle abstracts from the Keboola Connection Storage and communicates with your app using the simple directory structure - before starting your app, it downloads all required tables and files and after your app is done, it grabs all the results and uploads them back to Storage.

### Demo App

The [keboola/docker-demo-app](https://github.com/keboola/docker-demo-app) repository contains an example application. The demo application itself is represented by a single script `/src/run.php` (that would be the endpoint for the image). The application can exist independently (without Docker), contains unit and functional tests.

The [keboola/docker-demo-docker](https://github.com/keboola/docker-demo-docker) repository contains the Docker image definition in **Dockerfile**. Docker image definition prepares the docker environment including the application (the previous repository). A hook from Dockerhub builds the docker image automatically on every commit.  

## Creating an app

For developing the application logic of an image you can use any language and tool available in your chosen system. For the demo app we chose PHP and wrote a simple script in `/scr/run.php`. We also used `composer` to add some libraries to the application.

To run the demo app locally you can use the following command line:

	php ./src/run.php --data=/data

Make sure that 

  - you pass the directory with data to the script (so you can debug easier later)
  - the script does not do any output buffering for using `streaming_logs`
	
`/data` is path to a directory, where all source and configuration files are stored. The demo application needs a `/data/config.yml` configuration file and `/data/in/tables/source.csv` data file.
Our demo application reads data from `/data/in/tables/source.csv` and writes data to `/data/out/tables/sliced.csv`.

The content of the configuration file is describing input mapping from Storage and parameters for the PHP script:

	storage:
	  input:
	    tables:
	      0:
	        source: in.c-main.data
	        destination: source.csv
	  output:
	    tables:
	      0:
	        source: sliced.csv
	        destination: out.c-main.data
	parameters: 
	  primary_key_column: id
	  data_column: text
	  string_length: 255
	    	  	  	  	  
To create the directory and structure you can use `sandbox` or `input` API calls on the [Docker bundle API](http://docs.kebooladocker.apiary.io/#reference/sandbox). You'll get all the resources in a ZIP archive you need to access in your app. Use `sandbox` when you haven't registered the application with Keboola Connection (we don't know your preferred serialized configuration format yet) and `input` after the app is registered.

You can easily iterate and create new and new sandboxes as your app develops. All parameters you pass to the API calls are serialized into the configuration file so you don't need to modify the files by yourself.

This API call body creates the configuration file displayed above:
	    	  	  	  	  
	{
		"configData": {
			"storage": {
				"input": {
					"tables": [
						{
							"source": "in.c-main.data",
							"destination": "source.csv"
						}
					]
				},
				"output": {
					"tables": [
						{
							"source": "sliced.csv",
							"destination": "out.c-main.data"
						}
					]
				}
			},
			"parameters": {
				"primary_key_column": "id",
				"data_column": "text",
				"string_length": 255
			}
		}
	}
  
  
### Debugging

For debugging purposes it is possible to obtain the contents of the injected `/data` directory. There are three API calls for that purpose:
  
  - [Sandbox](http://docs.kebooladocker.apiary.io/#reference/sandbox/sandbox/create-a-sandbox-job)
  - [Input](http://docs.kebooladocker.apiary.io/#reference/sandbox/input-data/create-an-input-job)
  - [Dry run](http://docs.kebooladocker.apiary.io/#reference/sandbox/dry-run/create-a-dry-run-job)

The [Sandbox](http://docs.kebooladocker.apiary.io/#reference/sandbox) API call is useful for obtaining a sample environment configuration when starting with development of a new Docker image. 

The [Input](http://docs.kebooladocker.apiary.io/#reference/input) API call is useful for obtaining an environment configuration for existing docker image (registered as KBC component). 

The [Dry run](http://docs.kebooladocker.apiary.io/#reference/dry-run) API call is the last step. It will do everything except output mapping and is therefore useful for debugging an existing image without modifying and files and tables in KBC project.

Read more details about configuration in [ENVIRONMENT.md](ENVIRONMENT.md)

### Execution

When the application executes, it communicates with the environment using:

  - Environment variables
  - Standard Output and Standard Error
  - Exit code

#### Environment variables

The Docker bundle injects some environment variables that you can use in your script. See [Environment variables](https://github.com/keboola/docker-bundle/blob/master/ENVIRONMENT.md#environment-variables) for more details.

#### Standard Output and Standard Error

Docker bundle listens to `stdout` and `stderr` of the app and forwards any content live to [Storage API Events](http://docs.keboola.apiary.io/#events) (log levels `info` and `error`). Make sure your application does not use any output buffering or all events will be catched after the app finishes.

You can leverage Events to inform user of any progress, notices or troubleshooting information.

You can turn off live forwarding by setting `streaming_logs` to `false` in the image configuration (you need to ask us to set it).

#### Exit codes

When the app execution is finished, Docker bundle automatically collects the exit code and the content of STDOUT and STDERR.

  - `exit code = 0` the execution is considered successful (when `streaming_logs` is turned off the content of STDOUT will be sent to a Storage API Event)
  - `exit code = 1` the execution fails with an user exception and content of both STDOUT and STDERR will be sent to a Storage API Event
  - `exit code > 1` the execution fails with an application exception and content of both STDOUT and STDERR will be sent to our internal logs
  
You can leverage this to communicate any errors or significant events in your component. 

We're working on a best practice doc about handling application errors. Meanwhile ask us for some tips, if you need to handle application errors by yourself.

## Now what?

Looks like your application is ready. Now let's build a [Docker image with your app](HOWTO-DOCKERFILE.md).
