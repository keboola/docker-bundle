# Environment

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

A couple of environment variables are injected in the container:

 - `KBC_RUNID` - RunId from Storage, couples all events within an API call (use for logging)
 - `KBC_PROJECTID` - Id of the project in KBC
 - `KBC_PROJECTNAME` - Name of the project in KBC
 - `KBC_TOKENID` - Id of token running the container
 - `KBC_TOKENDESC` - Description (user name or token name) of the token running the container
 - `KBC_TOKEN` - Actual token running the container. Note that this environment is available only if enabled in component configuration. 

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

### Input Mapping

As a part of container configuration you can specify tables and files that will be downloaded and provided to the container.

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

All files that will match the fulltext search will be downloaded to the `/data/in/files` folder. Each file will also contain a manifest with all information about the file in the chosen format.

```
/data/in/files/75807542
/data/in/files/75807542.manifest
/data/in/files/75807657
/data/in/files/75807657.manifest		
```

`/data/in/files/75807542.manifest`:

```
  id: 75807657
  created: "2015-01-14T00:47:00+0100"
  is_public: false
  is_sliced: false
  is_encrypted: false
  name: "one_2015_01_05allkeys.json.zip"
  size_bytes: 563416
  tags: 
    - "keboola/docker-demo"
  max_age_days: 180
  creator_token: 
    id: 3800
    description: "ondrej.hlavacek@keboola.com"
```


### Output Mapping

Output mapping can be defined at multiple places - in configuration file or in manifests, for both tables and files.

Basically manifests allow you to process files in `/data/out` folder without defining them in the output mapping. That allows for flexible and dynamic output mapping, where the structure is unknown at the beginning.

#### Tables

In the simplest format, output mapping processes CSV files in the `/data/out/tables` folder and uploads them into tables. The name of the file may be equal to the name of the table (after removing `.csv` suffix).

Output mapping parameters are similar to [Transformation API output mapping ](http://wiki.keboola.com/home/keboola-connection/devel-space/transformations/intro#TOC-Output-mapping). If `source` is not set, the CSV file is expected to have the same name as the `destination` table.

The tables element in configuration is an array and supports these attributes:

  - `source`
  - `destination`
  - `incremental`
  - `primary_key`
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

Upload `/data/out/tables/data.csv` to `out.c-main.data`.
with a primary key and incrementally.

```
storage: 
  output:
    tables:
      0:
        source: data.csv
        destination: out.c-main.data
        incremental: 1
        primary_key: ["id"]
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

Output files from `/data/out/files` folder are automatically uploaded to file uploads. There are two ways how to define file upload options - configuration and manifest files, where manifest has a higher priority.

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

If the manifest file is defined, the information from the manifest file will be used instead of the configuration. 

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
