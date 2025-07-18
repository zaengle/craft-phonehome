# API Documentation

You can integrate your own monitoring solution that accesses the Phone Home API at `/actions/phonehome/api`. 

## API Versions

The Phone Home API is versioned to ensure compatibility with future changes. The API version in included in an `api_version` key in the response. The current version is `{{ __PLUGIN_VERSION__ }}`.

The API is designed to be stable, but may evolve over time. Always check the version in the response to ensure compatibility with your integration.

The API version uses semantic versioning, which means that:

- **Major version** changes indicate breaking changes that may require updates to your integration.
- **Minor version** changes may introduce new features or improvements without breaking existing functionality.
- **Patch version** changes are for bug fixes and do not affect the API's functionality.

Major/minor/patch versions will always be reflected in the plugin release version. We will commit not to introducing breaking changes outside of major version release.

## Making Requests

- Only `POST` requests are accepted. by the `/actions/phonehome/api` endpoint.
- Only `GET` requests are accepted by the `/actions/phonehome/schema` endpoint.
- Requests must include the correct Bearer token in a `x-auth-token` header.
- Requests must accept JSON responses.
- If the token is missing or invalid, the request will be rejected with a 401 Unauthorized error.

## API Schema

A JSON Schema for the API response is available at `/actions/phonehome/schema`. This schema describes the structure of the API response, including the fields and their types. You can use this schema to validate the API response in your integration. To avoid unnecessary leaking information, **requests for the schema still check for a valid token**. You can find a static copy of the schema in the `src/schemas/PhoneomeApi.schema.json` file.


## Sample API Response

 ```json

   ```
