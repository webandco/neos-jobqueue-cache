# Webandco.JobQueue.Cache

A job queue backend for the [Flowpack.JobQueue.Common](https://github.com/Flowpack/jobqueue-common)
package based on Caches available by [FLOW](https://flowframework.readthedocs.io/en/stable/TheDefinitiveGuide/PartIII/Caching.html).

The goal of this package is to have a job queue implementation at hand
in the case the project doesn't need or has no access to a database or redis.

If your project uses a [database](https://github.com/Flowpack/jobqueue-doctrine),
[redis](https://github.com/Flowpack/jobqueue-redis) or [beanstalkd](https://github.com/Flowpack/jobqueue-beanstalkd)
we highly recommend using the specific job queue packages, because the cache queue does not perform well with many or large
job's in the queue.

## Usage

Install the package using composer:

```
composer require webandco/neos-jobqueue-cache
```

Dependencies for this package are only FLOW and the `jobqueue-common` package.

In your `Settings.yaml` configure a queue like this
```yaml
Flowpack:
  JobQueue:
    Common:
      queues:
        SomeQueueName:
          className: 'Webandco\CacheQueue\Queue\Cache'
          executeIsolated: true
          options:
            defaultTimeout: 50
            pollInterval: 5
```

Additionally, you can modify the cache configuration. By default, the cache uses `FileBackend`.  
The cache itself should be `persistent` - see [`Caches.yaml`](Configuration/Caches.yaml) - to avoid cache flushes during
flow's cache flush mechanism's.

Finally, the cache queue needs to be created using

```
./flow queue:setup SomeQueueName
```

This command is needed only once, but can be called multiple times. In case the queue is already set-up the command does nothing.

## Specific options

The `CacheQueue` supports following options:

| Option                  | Type    | Default                                 | Description                              |
| ----------------------- |---------| ---------------------------------------:| ---------------------------------------- |
| defaultTimeout          | integer | 60                                      | Number of seconds new messages are waited for before a timeout occurs (This is overridden by a "timeout" argument in the `waitAndTake()` and `waitAndReserve()` methods |
| pollInterval            | integer | 1                                       | Number of seconds between lookups for new messages in the cache |

*NOTE:* The used `Cache` backend must implement `IterableBackendInterface` to allow to sort
the cache entries by creation date and retrieve the next message to process.

The following backends implement `IterableBackendInterface`:
* `ApcuBackend`
* `FileBackend`
* `PdoBackend`
* `RedisBackend`
* `SimpleFileBackend`

### Submit options

Additional options supported by `JobManager::queue()`, `DoctrineQueue::submit()` and the `Job\Defer` annotation:

| Option                  | Type    | Default          | Description                              |
| ----------------------- |---------| ----------------:| ---------------------------------------- |
| delay                   | integer | 0                | Number of seconds before a message is marked "ready" after submission. This can be useful to prevent premature execution of jobs (i.e. before entities are persisted) |

## License

This package is licensed under the MIT license
