# Changelog

## [Unreleased]

### Fixed
- A `CarrierException` no longer carries the private key and the security hash. The message quoted
  the HTTP client's (the whole URL), the previous was the client's own exception, and every trace of
  the chain kept the arguments of the calls under it (the request's options, the `Api` with its
  credentials) when `zend.exception_ignore_args` is off: a log of the whole chain wrote them. The
  message masks them as `***`, the previous is a copy of the client's exception (the same class and
  code), and no exception `Api` throws keeps its trace's arguments (`Omnibus\Http\Redaction`).
