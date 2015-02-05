# HOWTO

This guide will show you how you can create your own Docker image that can be run in Keboola Connection. To see it in action see `docker-demo` image on [GitHub](https://github.com/keboola/docker-demo) and [Dockerhub](https://registry.hub.docker.com/u/keboola/docker-demo/).


## Architecture

The [docker-demo repository on GitHub](https://github.com/keboola/docker-demo) contains both application logic and the docker image definition. A hook from Dockerhub builds the image automatically on every commit. 

`/src/script.php` is a PHP script, that performs the actual logic of the Docker image.

`/Dockerfile` describes how the image is built


## Develop

For developing the business logic of an image you can use any language and tool available in your chosen system. For our app I chose PHP and wrote a simple script in `/scr/script.php`. I also used `composer` to add some libraries.

I was developing the script locally and I was running it with this command line:

	php ./src/script.php --data=/data
	
Where `/data` is path to a directory, where all source and configuration files are stored. Basically all I needed was a `/data/config.yml` configuration file and `/data/in/tables/source.csv` data file.

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
	  
In productin this configuration is stored in [Storage API](http://docs.keboola.apiary.io/#components) and injected into the image along with other files and tables. This configuration is created manually using the API (less likely) or by using a UI within KBC (preferred way, the UI needs to be developed as well). 

I had the PHP script read from `/data/in/tables/source.csv` and write to `/data/out/tables/sliced.csv`. 

Read more details in [ENVIRONMENT.md](ENVIRONMENT.md)

## Build

Once the business logic was working, I started to build the Docker image in the Dockerfile.

	# VERSION 1.0.4
	FROM keboola:base-php
	MAINTAINER Ondrej Hlavacek <ondrej.hlavacek@keboola.com>
	
	WORKDIR /home
	
	# Initialize 
	RUN git clone https://github.com/keboola/docker-demo.git ./
	RUN composer install
	
	ENTRYPOINT php ./src/script.php --data=/data

All I needed to do is to clone the repository (to get the `/src` folder), run `composer install` and call the `php ./src/script.php --data=/data` command i was running locally. Docker bundle automatically provides all files (tables, file uploads and configuration) to the `/data` directory in the container, so you do not need to worry about any input or output mapping. 

_Note: if you do some changes in the business logic, commit them to the repository before building the image. Image downloads only the latest commit from the repo._

## Deploy

To deploy the Docker image to Keboola connection you need to publish your image to Dockerhub. You can do that automatically (if you do not have a public GitHub repo), or you can set up an automated build on Dockerhub, if the GitHub repository is public.

Once you have the image available in Dockerhub, let us know on [support@keboola.com](mailto:support@keboola.com) and we'll integrate it into our list of components.

The components usually require a UI to configure the input and output mapping and the parameters. Users could probably configure your image using a documentation, but using a UI is just much better. Talk to us.




