# HOWTO

This guide will show you how you can create your own Docker image that can be run in Keboola Connection. To see it in action see `docker-demo` image on [GitHub](https://github.com/keboola/docker-demo) and [Dockerhub](https://registry.hub.docker.com/u/keboola/docker-demo/).


## Architecture

The [docker-demo repository on GitHub](https://github.com/keboola/docker-demo) contains the docker image
application, create a new tag in the application and update the docker image definition in **Dockerfile**. 
definition. This prepares the docker environment including the application itself. Both - the docker image definition and the app itself are in a single repository, but you may choose to work with two separate repositories, one for the image definition and one for the application itself. A hook from Dockerhub builds the docker image automatically on every commit. The docker image refers to a specific version of the application. To release a new version of the 


The demo application itself represented by a single script `/src/script.php`. Which is a PHP script, that performs the actual logic of the Docker image.


## Develop

For developing the business logic of an image you can use any language and tool available in your chosen system. For the demo app we chose PHP and wrote a simple script in `/scr/script.php`. We also used `composer` to add some libraries to the application.

To develop and run the demo app localy you can use the following command line:

	php ./src/script.php --data=/data
	
Where `/data` is path to a directory, where all source and configuration files are stored. The demo application needs a `/data/config.yml` configuration file and `/data/in/tables/source.csv` data file.
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
	    	  	  	  	  
In production, this configuration is stored in [Storage API](http://docs.keboola.apiary.io/#components) and injected into the image along with other files and tables. This configuration can be created manually using the API (less likely) or by using a UI within KBC (preferred way, the UI needs to be developed as well). When using this option, only the configuration name has to be provided in the docker-bundle [API call body](http://docs.kebooladocker.apiary.io/#reference/run/create-a-job/stored-configuration):

	{
		"config": "my_config_name"
	}
 
During development, it is handy to be able to change the configuration quickly. In that case you can provide the whole configuration in the [API request body](http://docs.kebooladocker.apiary.io/#reference/run/create-a-job/custom-configuration):

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
  

For debugging purposes it is possible to generate the contents of the injected `/data` directory. The [Dry run](http://docs.kebooladocker.apiary.io/#reference/dry-run) API call will prepare the `/data` directory including the input mappings. The whole directory will be stored in your KBC Storage project as a zip archive. You use this archive to replicate the input in your local environment. Note that when using dry run, only a sample of 50 rows from each table will be exported. 

Read more details about configuration in [ENVIRONMENT.md](ENVIRONMENT.md)


## Build

Once the business logic was working, I started to build the Docker image in the Dockerfile.

	FROM keboola:base-php
	MAINTAINER Ondrej Hlavacek <ondrej.hlavacek@keboola.com>
	
	WORKDIR /home
	
	# Initialize 
	RUN git clone https://github.com/keboola/docker-demo.git ./
	RUN git checkout tags/1.0.0
	RUN composer install --no-interaction

	ENTRYPOINT php ./src/script.php --data=/data

All we is to clone the repository (to get the `/src` folder), run `composer install` and call the `php ./src/script.php --data=/data` command. The last line with **ENTRYPOINT** is actually the command that 
will get executed when the docker image is run. Docker bundle automatically provides all files (tables, file uploads and configuration) to the `/data` directory in the container, so you do not need to worry about any input or output mapping. 

_Note: if you do some changes in the business logic, don't forget to update the DockerFile repository and change the application version. Docker hub downloads only the latest commit from the repo and builds the image only if the Dockerfile changed._


## Deploy

To deploy the Docker image to Keboola connection you need to publish your image to Dockerhub. You can do that manually (if you do not have a public GitHub repo), or you can set up an automated build on Dockerhub, if the GitHub repository is public.

Once you have the image available in Dockerhub, let us know on [support@keboola.com](mailto:support@keboola.com) and we'll integrate it into our list of components.

The components usually require a UI to configure the input and output mapping and the parameters. Users could probably configure your image using a documentation, but using a UI is just much better. Talk to us.

