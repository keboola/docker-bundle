# Building a Docker image

Once the business logic of the app is working, you can build the Docker image using a Dockerfile. Example from [docker-demo repository](https://github.com/keboola/docker-demo):

	FROM keboola:base-php
	MAINTAINER Ondrej Hlavacek <ondrej.hlavacek@keboola.com>
	
	WORKDIR /home
	
	# Initialize 
	RUN git clone https://github.com/keboola/docker-demo-app.git ./
	RUN git checkout tags/0.1.0
	RUN composer install --no-interaction

	ENTRYPOINT php ./src/run.php --data=/data

All we need to do is to clone the repository from GitHub, checkout the selected version, run `composer install --no-interaction` and call the application endpoing: `php ./src/run.php --data=/data`. The last line with **ENTRYPOINT** is actually the command that will get executed when the Docker image is run. Docker bundle automatically provides all files (tables, file uploads and configuration) to the `/data` directory in the container, so you do not need to worry about any input or output mapping. 

We recommend checking out a specific tag, as Dockerhub caches the results all lines from Dockerfile and you won't get a newer repository from GitHub if you trigger a new build and the Dockerfile does not contain any changes. You could easily just change the comment on the first line, but the app version makes a bit more sense.

## Deployment to Dockerhub

To deploy the Docker image to Keboola Connection you need to publish your image to Dockerhub. You can do that manually (if you do not have a public GitHub repo), or you can set up an automated build on Dockerhub, if the GitHub repository is public.

## Application registration in Keboola Connection

Once you have the image available in Dockerhub, let us know on [support@keboola.com](mailto:support@keboola.com) and we'll integrate it into our list of components.

The components usually require a UI to configure the input and output mapping and the parameters. Users could probably configure your image using a documentation, but using a UI is just much better. Talk to us.
