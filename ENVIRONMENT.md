# Environment

## Overview

The application has several methods of integration with Keboola Connection

 - `/data` folder - table, file and configuration exchange
 - environment variables
 - `stdout`, `stderr` and `exit status` of the application script

### Data Folder

Data folder is a two way exchange of tables and files and a storage for configuration. The data folder is created in `/data` and has this structure
 
    /data
    /data/in
    /data/in/tables
    /data/in/files
    /data/out
    /data/out/tables
    /data/out/files

#### Serialized configuration  
  
Configuration is stored in `/data/config.yml` or `/data/config.json` depending on the preferred format. The configuration file will contain all information passed to the job in the API call. 
  
#### Tables & Files

Tables defined in the input mapping (source tables/files for the app) are stored in `/data/in/tables` and `/data/in/files`. Results of the application are retrieved from `/data/out/tables` and `/data/out/files`.

## Docker image

Docker image must be able to run as executable, `Dockerfile` must contain `ENTRYPOINT` or `CMD`.

We recommend to use our `keboola/base` image, as it might include some features in the future.


### Configuration

To be defined further, but you will have options to set:

  * format of injected config file and manifests (YAML, JSON)
  * container memory limit
  * whether or not the input and output mapping is provided by Keboola Connection


## Workflow

What happens before and after running a Docker container.

  - Download and build specified docker image
  - Download all tables and files specified in input mapping
  - Create configuration file (i.e config.yml)
  - Run the container
  - Upload all tables and files in output mapping
  - Delete the container and all temporary files


### Errors

The script defined in `ENTRYPOINT` or `CMD` can provide an exit status. Everything >0 is considered an error and then all content of `STDOUT` will be logged in the error detail. Exit status = 1 will be considered as an user exception, all other as application exceptions.

## Data & configuration injection

Keboola Connection will inject configuration and (optionally) an input mapping in the Docker container in `/data` folder. 

### Environment variables

These environment variables are injected in the container:

 - `KBC_RUNID` - RunId from Storage, couples all events within an API call (use for logging)
 - `KBC_PROJECTID` - Id of the project in KBC.
 
 The following are available only if enabled in component configuration:
 
 - `KBC_PROJECTNAME` - Name of the project in KBC.
 - `KBC_TOKENID` - Id of token running the container.
 - `KBC_TOKENDESC` - Description (user name or token name) of the token running the container. 
 - `KBC_TOKEN` - Actual token running the container.  

### Configuration

Note: all multiword parameter names are used with underscores instead of camel case.

The configuration file will be one of the following, depending on the image settings (default is yml).

 - `/data/config.yml`
 - `/data/config.json`
 
The configuration file will contain all configuration settings (including input and output mapping even if the mapping is provided by Keboola Connection).

Configuration file may contain these sections:

 - `storage` - list of input and output mappings and Storage API token, if required
 - `system` - copy of system configuration (eg. image tag)
 - `parameters` - variable parameters for the container

### State file

State file is used to store component's state for the next run. It provides a two-way communication between Keboola Connection configuration state storage and the application. State file only works if the API call references a stored configuration (`config` not `configData`).
 
 - `/data/in/state.yml` or `/data/in/state.json` loaded from configuration state storage
 - `/data/out/state.yml` or `/data/out/state.json` saved to configuration state storage

The application can write any content to the output state file (valid JSON or YAML) and that will be available to the next API call. Missing or empty file will remove the state value.
 
State object is is saved to configuration storage only when running the app (not in sandbox API calls).  

Note: state file must contain valid JSON or YAML objects. 


### Input Mapping

As a part of configuration you can specify tables and files that will be downloaded and provided to the container.

#### Tables

Tables from input mapping are mounted to `/data/in/tables`. 

Input mapping parameters are similar to [Storage API export table options ](http://docs.keboola.apiary.io/#tables). If `destination` is not set, the CSV file will have the same name as the table (without adding `.csv` suffix).

The tables element in configuration is an array and supports these attributes

  - `source`
  - `destination`
  - `changed_since`
  - `columns`
  - `where_column`
  - `where_operator`
  - `where_values`
  - `limit`

##### Examples

Download tables `in.c-ex-salesforce.Leads` and `in.c-ex-salesforce.Accounts` to `/data/tables/in/leads.csv` and `/data/tables/in/accounts.csv`

```
storage: 
  input:
    tables:
      0:
        source: in.c-ex-salesforce.Leads
        destination: leads.csv
      1:
        source: in.c-ex-salesforce.Accounts
        destination: accounts.csv

```


Download 2 days of data from table `in.c-storage.StoredData` to `/data/tables/in/in.c-storage.StoredData`

```
storage: 
  input:
    tables:
      0:
        source: in.c-storage.StoredData
        changed_since: -2 days  
```

Download only certain columns

```
storage: 
  input:
    tables:
      0:
        source: in.c-ex-salesforce.Leads
        columns: ["Id", "Revenue", "Date", "Status"]
```

Download filtered table

```
storage: 
  input:
    tables:
      0:
        source: in.c-ex-salesforce.Leads
        destination: closed_leads.csv
        where_column: Status
        where_values: ["Closed Won", "Closed Lost"]
        where_operator: eq
```


#### Files

You can also download files from file uploads using an ES query or filtering using tags. Note that the results of a file mapping are limited to 10 files (to prevent accidental downloads). If you need more files you can use multiple file mappings.  

```
storage: 
  input:
    files:
      0:
        tags:
          - keboola/docker-demo
        query: name:.zip
```

All files that will match the fulltext search will be downloaded to the `/data/in/files` folder. The name of each file has the format `fileId_fileName`. Each file will also contain a manifest with all information about the file in the chosen format.

```
/data/in/files/75807542_fooBar.jpg
/data/in/files/75807542_fooBar.jpg.manifest
/data/in/files/75807657_fooBarBaz.png
/data/in/files/75807657_fooBarBaz.png.manifest		
```

`/data/in/files/75807542_fooBar.jpg.manifest`:

```
  id: 75807657
  created: "2015-01-14T00:47:00+0100"
  is_public: false
  is_sliced: false
  is_encrypted: false
  name: "fooBar.jpg"
  size_bytes: 563416
  tags: 
    - "keboola/docker-demo"
  max_age_days: 180
  creator_token: 
    id: 3800
    description: "ondrej.hlavacek@keboola.com"
```

You can also use `processed_tags` option to define which tags will be applied to source files after they have been successfully processed. This allows you to set up incremental file processing pipeline. 

```
storage: 
  input:
    files:
      0:
        query: "tags: my-files AND NOT tags: downloaded",
        processed_tags: ["downloaded"]
```

This configuration adds `downloaded` tag to all processed files and the query will exclude them on the next run. 

You can also use `filter_by_run_id` option to select only files which are related to the job currently beeing executed. If `filter_by_run_id` is specified, we will download only files which satisfy the filter (either `tags` or `query`) *and* were uploaded by a parent job (a job with same or parent runId). This allows you to further limit downloaded files only to thoose related to a current chain of jobs.

```
storage: 
  input:
    files:
      0:
        tags: 
          - "fooBar"
        filter_by_run_id: true          
```

This will download only file which have the tag `fooBar` and were produced by a parent job to the currently running docker.

### Output Mapping

Output mapping can be defined at multiple places - in configuration file or in manifests, for both tables and files.

Basically manifests allow you to process files in `/data/out` folder without defining them in the output mapping. That allows for flexible and dynamic output mapping, where the structure is unknown at the beginning.

#### Tables

In the simplest format, output mapping processes CSV files in the `/data/out/tables` folder and uploads them into tables. The name of the file may be equal to the name of the table (after removing `.csv` suffix if present).

Output mapping parameters are similar to [Transformation API output mapping ](http://wiki.keboola.com/home/keboola-connection/devel-space/transformations/intro#TOC-Output-mapping). `destination` is the only required parameter. If `source` is not set, the CSV file is expected to have the same name as the `destination` table. 

The tables element in configuration is an array and supports these attributes:

  - `source`
  - `destination`
  - `incremental`
  - `delete_where_column`
  - `delete_where_column`
  - `delete_where_operator`

##### Examples

Upload `/data/out/tables/out.c-main.data.csv` to `out.c-main.data`.

```
storage: 
  output:
    tables:
      0:
        source: out.c-main.data.csv
        destination: out.c-main.data
```

Upload `/data/out/tables/data.csv` to `out.c-main.data` incrementally (behavior depends on whether the primary key on the target table is set or not).

```
storage: 
  output:
    tables:
      0:
        source: data.csv
        destination: out.c-main.data
        incremental: 1
```

Delete data from `destination` table before uploading the CSV file (only makes sense with `incremental: 1`).

```
storage: 
  output:
    tables:
      0:
        source: data.csv
        destination: out.c-main.Leads
        incremental: 1
        delete_where_column: Status
        delete_where_values: ["Closed"]
        delete_where_operator: eq              
```

##### Manifests

To allow dynamic data outputs, that cannot be determined before running the container, each file in `/data/out` directory can contain a manifest with the output mapping settings in the chosen format.

```
/data/out/tables/table.csv
/data/out/tables/table.csv.manifest
```

`/data/out/tables/table.csv.manifest`: 

```
destination: out.c-main.Leads
incremental: 1
```

#### Files

All output files from `/data/out/files` folder are automatically uploaded to file uploads. There are two ways how to define file upload options - configuration and manifest files, where manifest has a lower priority.

These parameters can be used (taken from [Storage API File Import](http://docs.keboola.apiary.io/#files)):

 - `is_public`
 - `is_permanent`
 - `notify`
 - `tags`
 - `is_encrypted`

##### Example

You can define files in the output mapping configuration using their filename (eg. `file.csv`). If that file is not present, docker-bundle will throw an exception. Note that docker-bundle will upload all files in the `/data/out/files` folder, not only those specified in the output mapping.

```
storage: 
  output:
    files:
      0:
        source: file.csv
        tags: 
          - processed-file
          - csv
      1:
        source: image.jpg
        is_public: true
        is_permanent: true
        tags: 
          - image
          - pie-chart
```


##### Manifests

If the manifest file is defined, the information from the manifest file will be used with lower priority than configuration. 

```
/data/out/files/file.csv
/data/out/files/file.csv.manifest
/data/out/files/image.jpg
/data/out/files/image.jpg.manifest
```

`/data/out/files/table.csv.manifest`: 

```
tags: 
  - processed-file
  - csv
```


`/data/out/files/image.jpg.manifest`: 

```
is_public: true
is_permanent: true
tags: 
  - image
  - pie-chart
```

### Configuration for incremental file processing

Docker containers may be used to process unknown files incrementally. This means that when a container is run, it will download any files not yet downloaded, and process them. To achieve this behavior, it is necessary to select only the files which have not been processed yet and to tag processed files somehow. The former can be achieved by using proper [ElasticSerch query](http://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-query-string-query.html#query-string-syntax) and the latter is achieved using the `processed_tags` setting. `processed_tags` setting is an array of tags which will be added to the *input* files once they are downloaded. A sample API request:  

```
{
    "configData": {
        "storage": {
            "input": {
                "files": [
                    {
                        "query": "tags: toprocess AND NOT tags: downloaded",
				         "processed_tags": [
                            "downloaded", "my-image"
                        ]
                    }
                ]
            }
        },
        "parameters": {
            ...
        }
    }
}
```

The above request causes docker bundle to download every file with tag `toprocess` except for files which have tag `downloaded`. It will mark each such file with tags `downloaded` and `my-image`. 

## Encryption

Docker provides encryption methods to store sensitive data in image definition, stored configurations or jobs. When running a job or saving a configuration all sensitive data will be encrypted and the decrypted state will be only available in the serialized configuration file inside the container. There are no other means of accessing encrypted data. To enable this behavior the component has to have the `encrypt` flag (contact us for enabling). Note that only attributes prefixed with `#` will be encrypted.

To encrypt strings or JSON structures use the [Encrypt data](http://docs.kebooladocker.apiary.io/#reference/encrypt/configuration-encryption/encrypt-data) API call. Storing the configuration in the UI will automatically encrypt the data.

### Encryption keys

The compound encryption key consists of 3 parts

 - general encryption key (stored in a secure location within VPC)
 - project id
 - component id
 
This mechanism ensures that the encrypted data will be accessible only for the specified component and project.

### Decryption

Decryption is only executed when serializing configuration to the configuration file for the Docker container. The decrypted data will be stored on the Docker host drive and will be deleted after the container finishes. Your application will always read the decrypted data.   

### Sandboxes

Sandbox calls are disabled for components with `encrypt` flag. This prevents decrypted data from leaking into sandbox archive files stored in file uploads. 