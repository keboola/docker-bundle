#Docker Bundle

[![Build Status](https://travis-ci.org/keboola/docker-bundle.svg?branch=master)](https://travis-ci.org/keboola/docker-bundle) [![Code Climate](https://codeclimate.com/github/keboola/docker-bundle/badges/gpa.svg)](https://codeclimate.com/github/keboola/docker-bundle) [![Test Coverage](https://codeclimate.com/github/keboola/docker-bundle/badges/coverage.svg)](https://codeclimate.com/github/keboola/docker-bundle)

Docker Bundle provides an interface for running [Docker](https://www.docker.com/) images in Keboola Connection. By developing functionality in Docker you'll focus only on the application logic, all communication with Storage API will be handled by Docker bundle.

Docker bundle's functionality can be described in few simple steps:

 - Download your Docker image from Dockerhub
 - Download all required tables and files from Storage (will be mounted into `/data`)
 - Run the container
 - Upload all result tables and files to Storage

## Read more 

###[HOWTO.md](HOWTO.md) 

Start developing a new app

###[HOWTO-DOCKERFILE.md](HOWTO-DOCKERFILE.md)

Encapsualte your app into an Docker image

###[ENVIRONMENT.md](ENVIRONMENT.md)

Defines the interface between KBC and your Docker image
