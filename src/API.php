<?php
namespace Patreon;

class API
{

    // Holds the access token
    private $access_token;

    // Holds the api endpoint used
    public $api_endpoint;

    // The cache for request results - an array that matches md5 of the unique API request to the returned result
    public $request_cache;

    // Sets the reqeuest method for cURL
    public $api_request_method = 'GET';

    // Holds POST for cURL for requests other than GET
    public $curl_postfields = false;

    // Sets the format the return from the API is parsed and returned - array (assoc), object, or raw JSON
    public $api_return_format;


    public function __construct($access_token)
    {
        // Set the access token
        $this->access_token = $access_token;

        // Set API endpoint to use. Its currently V2
        $this->api_endpoint = "https://www.patreon.com/api/oauth2/v2/";

        // Set default return format - this can be changed by the app using the lib by setting it
        // after initialization of this class
        $this->api_return_format = 'array';
    }

    public function getUser($query = [])
    {
        if (empty($query['include'])) {
            $query['include'] = [
                'campaign',
                'memberships',
                'memberships.campaign',
                'memberships.currently_entitled_tiers',
            ];
        }

        if (empty($query['fields'])) {
            $query['fields'] = [
                'user' => [
                    'email',
                    'first_name',
                    'full_name',
                    'image_url',
                    'last_name',
                    'thumb_url',
                    'url',
                    'vanity',
                    'is_email_verified'
                ],
                'member' => [
                    'currently_entitled_amount_cents',
                    'lifetime_support_cents',
                    'last_charge_status',
                    'patron_status',
                    'last_charge_date',
                    'pledge_relationship_start',
                ],
            ];
        }

        // Fetches details of the current token user.
        return $this->get_data('identity', $query);
    }

    public function getCampaigns($query = [])
    {
        if (empty($query['fields'])) {
            $query['fields'] = [
                'campaign' => [
                    'creation_name',
                ],
            ];
        }

        // Fetches the list of campaigns of the current token user. Requires the current user to be creator of the campaign or requires a creator access token
        return $this->get_data('campaigns', $query);
    }

    public function getCampaign($campaign_id, $query = [])
    {
        if (empty($query['include'])) {
            $query['include'] = [
                'benefits',
                'creator',
                'goals',
                'tiers',
            ];
        }

        if (empty($query['fields'])) {
            $query['fields'] = [
                'campaign' => [
                    'creation_name',
                    'vanity',
                ],
            ];
        }

        // Fetches details about a campaign - the membership tiers, benefits, creator and goals.  Requires the current user to be creator of the campaign or requires a creator access token
        return $this->get_data('campaigns/' . $campaign_id, $query);
    }

    public function getMember($member_id, $query = [])
    {
        if (empty($query['include'])) {
            $query['include'] = [
                'address',
                'campaign',
                'user',
                'currently_entitled_tiers',
            ];
        }

        // Fetches details about a member from a campaign. Member id can be acquired from fetch_page_of_members_from_campaign
        // currently_entitled_tiers is the best way to get info on which membership tiers the user is entitled to.  Requires the current user to be creator of the campaign or requires a creator access token.
        return $this->get_data('members/' . $member_id, $query);
    }

    public function getCampaignMembers($campaign_id, $query = [], $page_size = 50, $page_cursor = null)
    {
        $query['page'] = array_filter([
            'size'   => $page_size,
            'cursor' => $page_cursor
        ]);

        // Fetches a given page of members with page size and cursor point. Can be used to iterate through lists of members for a given campaign. Campaign id can be acquired from fetch_campaigns or from a saved campaign id variable.  Requires the current user to be creator of the campaign or requires a creator access token
        return $this->get_data('campaigns/' . $campaign_id . '/members', $query);
    }

    public function getWebhooks($query = [], $page_size = 50, $page_cursor = null)
    {
        $query['page'] = array_filter([
            'size'   => $page_size,
            'cursor' => $page_cursor
        ]);

        if (empty($query['fields'])) {
            $query['fields'] = [
                'webhook' => [
                    'uri',
                    'secret',
                    'paused',
                    'triggers',
                ],
            ];
        }

        return $this->get_data('webhooks', $query);
    }

    public function createWebhook($campaign_id, $uri, $triggers = [])
    {
        return $this->get_data('webhooks', [], [
            'data' => [
                'type' => 'webhook',
                'attributes' => [
                    'triggers' => $triggers,
                    'uri'      => $uri,
                ],
                'relationships' => [
                    'campaign' => [
                        'data' => [
                            'type' => 'campaign',
                            'id' => $campaign_id,
                        ]
                    ]
                ]
            ]
        ]);
    }

    public function updateWebhook($webhook_id, $uri, $triggers = [], $paused = null)
    {
        return $this->get_data('webhooks/' . $webhook_id, [], [
            'data' => [
                'id' => $webhook_id,
                'type' => 'webhook',
                'attributes' => array_filter([
                    'triggers' => $triggers,
                    'uri'      => $uri,
                    'paused'   => $paused,
                ]),
            ],
        ]);
    }

    public function parse_query($query = [])
    {
        $query_string = [];

        if (! empty($query['include'])) {
            $query_string[] = 'include=' . implode(',', $query['include']);
        }

        if (! empty($query['fields'])) {
            foreach ($query['fields'] as $field => $list) {
                $query_string[] = 'fields' . urlencode('[' . $field . ']') . '=' . implode(',', $list);
            }
        }

        if (! empty($query['page'])) {
            foreach ($query['page'] as $field => $value) {
                $query_string[] = 'page' . urlencode('[' . $field . ']') . '=' . $value;
            }
        }

        return ! empty($query_string) ? '?' . implode('&', $query_string) : '';
    }

    public function process_relationships($data, $included = [], $include_processed = false, $test = false)
    {
        $includes = [];

        if (! $include_processed) {
            foreach ($included as $include) {
                if (! isset($includes[$include['type']])) {
                    $includes[$include['type']] = [];
                }
                $includes[$include['type']][$include['id']] = $include;
            }

            foreach ($includes as $type => &$items) {
                foreach ($items as $id => &$include) {
                    if (! empty($include['relationships'])) {
                        $include['relationships'] = $this->process_relationships($include['relationships'], $includes, true);
                    }
                }
            }
        } else {
            $includes = $included;
        }

        foreach ($data as $key => &$relations) {
            if (! empty($relations['data'])) {
                $relationships = [];
                if (isset($relations['data'][0])) {
                    foreach ($relations['data'] as &$relation) {
                        if (! isset($relation['type'])) {
                            continue;
                        }
                        if (isset($includes[$relation['type']]) && isset($includes[$relation['type']][$relation['id']])) {
                            $relationships[$relation['id']] = $includes[$relation['type']][$relation['id']];
                        }
                    }
                } elseif (isset($includes[$relations['data']['type']]) && isset($includes[$relations['data']['type']][$relations['data']['id']])) {
                    $relationships = $includes[$relations['data']['type']][$relations['data']['id']];
                }
                $relations = $relationships;
            } else {
                $relations = [];
            }
        }

        return $data;
    }

    public function get_data($suffix, $query = [], $data = [], $headers = [], $args = [])
    {
        // Construct request:
        $api_request = $this->api_endpoint . $suffix . $this->parse_query($query);

        // This identifies a unique request
        $api_request_hash = md5($this->access_token . $api_request . (empty($data) ? 'true' : 'false'));

        // Check if this request exists in the cache and if so, return it directly - avoids repeated requests to API in the same page run for same request string

        if (! isset($args['skip_read_from_cache']) && empty($data)) {
            if (isset($this->request_cache[$api_request_hash])) {
                return $this->request_cache[$api_request_hash];
            }
        }

        // Request is new - actually perform the request

        $ch = $this->__create_ch($api_request, $data, $arg['request_type'] ?? null, $headers);
        $json_string = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        // Parse the return according to the format set by api_return_format variable
        if ($this->api_return_format == 'array') {
            $return = json_decode($json_string, true);
            if (isset($return['data'][0])) {
                foreach ($return['data'] as &$data) {
                    if (! empty($data['relationships']) && ! empty($return['included'])) {
                        $data['relationships'] = $this->process_relationships($data['relationships'], $return['included']);
                    }
                }
                unset($return['included']);
            }
            else if (! empty($return['data']['relationships']) && ! empty($return['included'])) {
                $return['data']['relationships'] = $this->process_relationships($return['data']['relationships'], $return['included']);
                unset($return['included']);
            }
        } else {
            $return = $json_string;
        }

        // Add this new request to the request cache and return it
        return $this->add_to_request_cache($api_request_hash, $return);

    }

    private function __create_ch($api_request, $data = [], $type = null, $headers = [])
    {
        // This function creates a cURL handler for a given URL. In our case, this includes entire API request, with endpoint and parameters

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_request);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        if (! empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        // Set the cURL request method - works for all of them
        if (! empty($type)) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $type);
        }

        $headers = array_merge($headers, [
            'Authorization: Bearer ' . $this->access_token,
            'User-Agent: Patreon-PHP, version 1.0.2, platform ' . php_uname('s') . '-' . php_uname( 'r' ),
        ]);

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        return $ch;

    }

    public function add_to_request_cache( $api_request_hash, $result )
    {
        // This function manages the array that is used as the cache for API requests. What it does is to accept a md5 hash of entire query string (GET, with url, endpoint and options and all) and then add it to the request cache array
        // If the cache array is larger than 50, snip the first item. This may be increased in future

        if ( !empty($this->request_cache) && (count( $this->request_cache ) > 50)  ) {
            array_shift( $this->request_cache );
        }

        // Add the new request and return it
        return $this->request_cache[$api_request_hash] = $result;
    }
}
